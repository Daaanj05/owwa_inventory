<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\InspectionAcceptanceReportLine;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InspectionAcceptanceReportWorkflowService
{
    public function __construct(
        protected ReferenceCodeService $referenceCodes,
    ) {}

    public function createFromApprovedPo(PurchaseOrder $purchaseOrder): InspectionAcceptanceReport
    {
        return DB::transaction(function () use ($purchaseOrder): InspectionAcceptanceReport {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($purchaseOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseOrder->isArchived()) {
                throw ValidationException::withMessages(['purchase_order' => 'Archived purchase orders cannot be used for an IAR.']);
            }

            if (! $purchaseOrder->isApproved()) {
                throw ValidationException::withMessages(['purchase_order' => 'Choose an approved purchase order.']);
            }

            if ($purchaseOrder->inspectionAcceptanceReport()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'This purchase order already has an IAR.']);
            }

            $purchaseOrder->loadMissing('orderedLines');

            if ($purchaseOrder->orderedLines->isEmpty()) {
                throw ValidationException::withMessages(['purchase_order' => 'The selected PO has no ordered line items.']);
            }

            $iarNumber = $this->referenceCodes->forAcquisitionPaperworkIar();

            $iar = InspectionAcceptanceReport::query()->create([
                'purchase_order_id' => $purchaseOrder->id,
                'recorded_by' => auth()->id(),
                'number' => $iarNumber,
                'status' => InspectionAcceptanceReport::STATUS_DRAFT,
                'iar_date' => now()->toDateString(),
            ]);

            foreach ($purchaseOrder->orderedLines->values() as $index => $line) {
                InspectionAcceptanceReportLine::query()->create([
                    'inspection_acceptance_report_id' => $iar->id,
                    'purchase_order_line_id' => $line->id,
                    'acquisition_paperwork_line_id' => $line->acquisition_paperwork_line_id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'pr_quantity' => (int) $line->pr_quantity,
                    'po_quantity' => (int) $line->po_quantity,
                    'iar_quantity' => (int) $line->po_quantity,
                    'unit_cost' => $line->unit_cost,
                    'amount' => $line->amount,
                    'line_remarks' => $line->line_remarks,
                    'sort_order' => $index,
                ]);
            }

            $purchaseOrder->purchaseRequest?->update([
                'phase' => AcquisitionPaperwork::PHASE_IAR,
                'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
                'iar_date' => $iar->iar_date,
                'iar_number' => $iarNumber,
            ]);

            return $iar->fresh(['lines', 'purchaseOrder.purchaseRequest']) ?? $iar;
        });
    }

    public function submit(InspectionAcceptanceReport $iar): InspectionAcceptanceReport
    {
        if ($iar->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived IARs cannot be submitted.']);
        }

        if ($iar->isApproved()) {
            throw ValidationException::withMessages(['phase' => 'IAR is already approved.']);
        }

        $missing = $iar->fresh(['lines'])?->missingFields() ?? $iar->missingFields();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $missing).'.',
            ]);
        }

        $number = $iar->number;
        if (blank($number)) {
            $number = $this->referenceCodes->forAcquisitionPaperworkIar();
        }

        $iar->update([
            'number' => $number,
            'submitted_at' => $iar->submitted_at ?? now(),
        ]);

        $iar->purchaseOrder?->purchaseRequest?->update([
            'iar_number' => $number,
            'iar_submitted_at' => $iar->submitted_at ?? now(),
            'iar_date' => $iar->iar_date,
            'inspection_officer_name' => $iar->inspection_officer_name,
            'custodian_name' => $iar->custodian_name,
            'iar_data' => [
                'invoice_no' => $iar->invoice_number,
                'invoice_date' => $iar->invoice_date?->toDateString(),
                'date_inspected' => $iar->date_inspected?->toDateString(),
                'date_received' => $iar->date_received?->toDateString(),
            ],
        ]);

        return $iar->fresh() ?? $iar;
    }

    public function approve(InspectionAcceptanceReport $iar): InspectionAcceptanceReport
    {
        if ($iar->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived IARs cannot be approved.']);
        }

        if ($iar->isApproved()) {
            throw ValidationException::withMessages(['phase' => 'IAR is already approved.']);
        }

        if (! $iar->isDraft() && ! $iar->isPendingApproval()) {
            throw ValidationException::withMessages(['phase' => 'IAR cannot be approved in its current status.']);
        }

        $missing = $iar->fresh(['lines'])?->missingFields() ?? $iar->missingFields();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $missing).'.',
            ]);
        }

        $number = $iar->number;
        if (blank($number)) {
            $number = $this->referenceCodes->forAcquisitionPaperworkIar();
        }

        $iar->update([
            'number' => $number,
            'status' => InspectionAcceptanceReport::STATUS_APPROVED,
            'approved_at' => now(),
            'submitted_at' => $iar->submitted_at ?? now(),
        ]);

        $iar->purchaseOrder?->purchaseRequest?->update([
            'iar_number' => $number,
            'iar_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'iar_completed_at' => now(),
            'phase' => AcquisitionPaperwork::PHASE_IAR,
            'iar_date' => $iar->iar_date,
            'inspection_officer_name' => $iar->inspection_officer_name,
            'custodian_name' => $iar->custodian_name,
            'iar_data' => [
                'invoice_no' => $iar->invoice_number,
                'invoice_date' => $iar->invoice_date?->toDateString(),
                'date_inspected' => $iar->date_inspected?->toDateString(),
                'date_received' => $iar->date_received?->toDateString(),
            ],
        ]);

        return $iar->fresh() ?? $iar;
    }

    /**
     * @return Collection<int, Acquisition>
     */
    public function recordCustodyReceipts(InspectionAcceptanceReport $iar): Collection
    {
        return DB::transaction(function () use ($iar): Collection {
            $iar = InspectionAcceptanceReport::query()
                ->whereKey($iar->id)
                ->lockForUpdate()
                ->with(['lines', 'purchaseOrder.purchaseRequest'])
                ->firstOrFail();

            if (! $iar->isApproved()) {
                throw ValidationException::withMessages(['phase' => 'IAR must be approved before recording custodian receipt.']);
            }

            if ($iar->isReceived()) {
                throw ValidationException::withMessages(['phase' => 'Custodian receipts already recorded for this IAR.']);
            }

            if ($iar->date_received === null) {
                throw ValidationException::withMessages(['phase' => 'Receive Date is required before recording custodian receipt.']);
            }

            if ($iar->date_received->copy()->startOfDay()->isFuture()) {
                throw ValidationException::withMessages(['phase' => 'Receive Date must be today or earlier before recording custodian receipt.']);
            }

            $lines = $iar->lines->filter(fn (InspectionAcceptanceReportLine $line): bool => $line->iar_quantity > 0);

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['phase' => 'Add at least one IAR quantity before recording custodian receipt.']);
            }

            $purchaseOrder = $iar->purchaseOrder;
            $paperwork = $purchaseOrder?->purchaseRequest;
            $source = trim('PO '.($purchaseOrder?->number ?? '').' / IAR '.($iar->number ?? ''));
            $acquisitionDate = $iar->date_received ?? $iar->iar_date ?? now();
            $created = collect();

            foreach ($lines as $line) {
                $created->push(Acquisition::query()->create([
                    'acquisition_paperwork_id' => $paperwork?->id,
                    'acquisition_paperwork_line_id' => $line->acquisition_paperwork_line_id,
                    'purchase_order_id' => $purchaseOrder?->id,
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'inspection_acceptance_report_id' => $iar->id,
                    'inspection_acceptance_report_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'office_id' => $paperwork?->office_id,
                    'quantity' => $line->iar_quantity,
                    'unit_cost' => $line->unit_cost ?? 0,
                    'acquisition_date' => $acquisitionDate,
                    'source' => $source,
                    'recorded_by' => auth()->id(),
                ]));
            }

            $iar->update(['stock_received_at' => now()]);
            $paperwork?->update(['received_at' => now()]);

            return $created;
        });
    }

    public function archive(InspectionAcceptanceReport $iar): InspectionAcceptanceReport
    {
        $iar->update(['archived_at' => now()]);

        return $iar;
    }

    public function restore(InspectionAcceptanceReport $iar): InspectionAcceptanceReport
    {
        $iar->update(['archived_at' => null]);

        return $iar;
    }
}
