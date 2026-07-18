<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Support\SupplyOfficeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderWorkflowService
{
    public function __construct(
        protected ReferenceCodeService $referenceCodes,
    ) {}

    public function createFromApprovedPr(AcquisitionPaperwork $paperwork): PurchaseOrder
    {
        return DB::transaction(function () use ($paperwork): PurchaseOrder {
            $paperwork = AcquisitionPaperwork::query()
                ->whereKey($paperwork->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($paperwork->isArchived()) {
                throw ValidationException::withMessages(['purchase_request' => 'Archived purchase requests cannot be used for a PO.']);
            }

            if (! $paperwork->isPrApproved()) {
                throw ValidationException::withMessages(['purchase_request' => 'Choose an offline-approved purchase request.']);
            }

            if ($paperwork->purchaseOrder()->exists()) {
                throw ValidationException::withMessages(['purchase_request' => 'This purchase request already has a purchase order.']);
            }

            $paperwork->loadMissing('lines');

            if ($paperwork->lines->isEmpty()) {
                throw ValidationException::withMessages(['purchase_request' => 'The selected PR has no line items.']);
            }

            $placeOfDelivery = app(SupplyOfficeResolver::class)->resolveOfficeName();

            $purchaseOrder = PurchaseOrder::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'recorded_by' => auth()->id(),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'po_date' => now()->toDateString(),
                'place_of_delivery' => $placeOfDelivery,
                'delivery_term' => 'FOB Destination',
            ]);

            foreach ($paperwork->lines->values() as $index => $line) {
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'acquisition_paperwork_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'pr_quantity' => (int) $line->quantity,
                    'po_quantity' => (int) $line->quantity,
                    'is_ordered' => true,
                    'unit_cost' => $line->unit_cost,
                    'amount' => $line->amount,
                    'line_remarks' => $line->line_remarks,
                    'sort_order' => $index,
                ]);
            }

            $paperwork->update([
                'phase' => AcquisitionPaperwork::PHASE_PO,
                'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
                'po_date' => $purchaseOrder->po_date,
            ]);

            return $purchaseOrder->fresh(['lines', 'purchaseRequest']) ?? $purchaseOrder;
        });
    }

    public function rememberSupplier(PurchaseOrder $purchaseOrder): void
    {
        if (blank($purchaseOrder->supplier_name)) {
            return;
        }

        $supplier = Supplier::remember(
            (string) $purchaseOrder->supplier_name,
            $purchaseOrder->supplier_tin,
            $purchaseOrder->supplier_address,
        );

        if ($purchaseOrder->supplier_id !== $supplier->id) {
            $purchaseOrder->update([
                'supplier_id' => $supplier->id,
                'supplier_tin' => $purchaseOrder->supplier_tin ?: $supplier->tin,
            ]);
        }
    }

    public function submit(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived purchase orders cannot be submitted.']);
        }

        if ($purchaseOrder->isApproved()) {
            throw ValidationException::withMessages(['phase' => 'PO is already approved.']);
        }

        $this->rememberSupplier($purchaseOrder);

        $missing = $purchaseOrder->fresh()?->missingFields() ?? $purchaseOrder->missingFields();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $missing).'.',
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
            'submitted_at' => now(),
        ]);

        $purchaseOrder->purchaseRequest?->update([
            'po_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'po_submitted_at' => now(),
            'supplier' => $purchaseOrder->supplier_name,
            'po_date' => $purchaseOrder->po_date,
            'po_data' => [
                'address' => $purchaseOrder->supplier_address,
                'tin' => $purchaseOrder->supplier_tin,
                'mode_of_procurement' => $purchaseOrder->mode_of_procurement,
                'place_of_delivery' => $purchaseOrder->place_of_delivery,
                'delivery_term' => $purchaseOrder->delivery_term,
                'date_of_delivery' => $purchaseOrder->date_of_delivery?->toDateString(),
                'payment_term' => $purchaseOrder->payment_term,
                'technical_specifications' => $purchaseOrder->technical_specifications,
            ],
        ]);

        return $purchaseOrder->fresh() ?? $purchaseOrder;
    }

    public function approve(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived purchase orders cannot be approved.']);
        }

        if ($purchaseOrder->isApproved()) {
            throw ValidationException::withMessages(['phase' => 'PO is already approved.']);
        }

        if (! $purchaseOrder->isPendingApproval()) {
            throw ValidationException::withMessages(['phase' => 'Submit PO for approval before marking approved.']);
        }

        $purchaseOrder->update([
            'number' => $this->referenceCodes->forAcquisitionPaperworkPo(),
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $purchaseOrder->purchaseRequest?->update([
            'po_number' => $purchaseOrder->number,
            'po_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'po_completed_at' => now(),
            'phase' => AcquisitionPaperwork::PHASE_PO,
        ]);

        return $purchaseOrder->fresh() ?? $purchaseOrder;
    }

    public function archive(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $purchaseOrder->update(['archived_at' => now()]);

        return $purchaseOrder;
    }

    public function restore(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $purchaseOrder->update(['archived_at' => null]);

        return $purchaseOrder;
    }
}
