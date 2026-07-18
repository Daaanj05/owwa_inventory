<?php

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\InspectionAcceptanceReportLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        AcquisitionPaperwork::query()
            ->orderBy('id')
            ->each(function (AcquisitionPaperwork $paperwork): void {
                $hasPoEvidence = filled($paperwork->po_number)
                    || filled($paperwork->po_date)
                    || filled($paperwork->supplier)
                    || filled($paperwork->po_data)
                    || filled($paperwork->po_submitted_at)
                    || filled($paperwork->po_completed_at)
                    || in_array($paperwork->po_status, ['pending_approval', 'approved'], true)
                    || in_array($paperwork->phase, ['po', 'iar'], true);

                if (! $hasPoEvidence) {
                    return;
                }

                if ($paperwork->purchaseOrder()->exists()) {
                    return;
                }

                DB::transaction(function () use ($paperwork): void {
                    $poData = is_array($paperwork->po_data) ? $paperwork->po_data : [];
                    $supplier = null;

                    if (filled($paperwork->supplier)) {
                        $supplier = Supplier::remember(
                            (string) $paperwork->supplier,
                            $poData['tin'] ?? null,
                            $poData['address'] ?? null,
                        );
                    }

                    $status = match ($paperwork->po_status) {
                        'approved' => PurchaseOrder::STATUS_APPROVED,
                        'pending_approval' => PurchaseOrder::STATUS_PENDING_APPROVAL,
                        default => PurchaseOrder::STATUS_DRAFT,
                    };

                    $purchaseOrder = PurchaseOrder::query()->create([
                        'acquisition_paperwork_id' => $paperwork->id,
                        'supplier_id' => $supplier?->id,
                        'recorded_by' => $paperwork->recorded_by,
                        'number' => $paperwork->po_number,
                        'status' => $status,
                        'po_date' => $paperwork->po_date,
                        'supplier_name' => $paperwork->supplier,
                        'supplier_address' => $poData['address'] ?? null,
                        'supplier_tin' => isset($poData['tin']) ? Supplier::normalizeTin((string) $poData['tin']) : null,
                        'mode_of_procurement' => $poData['mode_of_procurement'] ?? null,
                        'place_of_delivery' => $poData['place_of_delivery'] ?? null,
                        'delivery_term' => $poData['delivery_term'] ?? null,
                        'date_of_delivery' => $poData['date_of_delivery'] ?? null,
                        'payment_term' => $poData['payment_term'] ?? null,
                        'technical_specifications' => $poData['technical_specifications'] ?? $paperwork->remarks,
                        'remarks' => $paperwork->remarks,
                        'submitted_at' => $paperwork->po_submitted_at,
                        'approved_at' => $paperwork->po_completed_at,
                    ]);

                    $paperwork->loadMissing('lines');

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

                    $hasIarEvidence = filled($paperwork->iar_number)
                        || filled($paperwork->iar_date)
                        || filled($paperwork->iar_data)
                        || filled($paperwork->iar_submitted_at)
                        || filled($paperwork->iar_completed_at)
                        || filled($paperwork->inspection_officer_name)
                        || filled($paperwork->custodian_name)
                        || in_array($paperwork->iar_status, ['pending_approval', 'approved'], true)
                        || $paperwork->phase === 'iar';

                    if (! $hasIarEvidence) {
                        return;
                    }

                    $iarData = is_array($paperwork->iar_data) ? $paperwork->iar_data : [];
                    $iarStatus = match ($paperwork->iar_status) {
                        'approved' => InspectionAcceptanceReport::STATUS_APPROVED,
                        'pending_approval' => InspectionAcceptanceReport::STATUS_PENDING_APPROVAL,
                        default => InspectionAcceptanceReport::STATUS_DRAFT,
                    };

                    $iar = InspectionAcceptanceReport::query()->create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'recorded_by' => $paperwork->recorded_by,
                        'number' => $paperwork->iar_number,
                        'status' => $iarStatus,
                        'iar_date' => $paperwork->iar_date,
                        'invoice_number' => $iarData['invoice_no'] ?? null,
                        'invoice_date' => $iarData['invoice_date'] ?? null,
                        'date_inspected' => $iarData['date_inspected'] ?? null,
                        'date_received' => $iarData['date_received'] ?? null,
                        'inspection_officer_name' => $paperwork->inspection_officer_name,
                        'custodian_name' => $paperwork->custodian_name,
                        'remarks' => $paperwork->remarks,
                        'submitted_at' => $paperwork->iar_submitted_at,
                        'approved_at' => $paperwork->iar_completed_at,
                        'stock_received_at' => $paperwork->received_at,
                    ]);

                    $purchaseOrder->loadMissing('lines');

                    foreach ($purchaseOrder->lines->where('is_ordered', true)->values() as $index => $poLine) {
                        InspectionAcceptanceReportLine::query()->create([
                            'inspection_acceptance_report_id' => $iar->id,
                            'purchase_order_line_id' => $poLine->id,
                            'acquisition_paperwork_line_id' => $poLine->acquisition_paperwork_line_id,
                            'item_id' => $poLine->item_id,
                            'description' => $poLine->description,
                            'unit' => $poLine->unit,
                            'pr_quantity' => (int) $poLine->pr_quantity,
                            'po_quantity' => (int) $poLine->po_quantity,
                            'iar_quantity' => (int) $poLine->po_quantity,
                            'unit_cost' => $poLine->unit_cost,
                            'amount' => $poLine->amount,
                            'line_remarks' => $poLine->line_remarks,
                            'sort_order' => $index,
                        ]);
                    }

                    $iar->loadMissing('lines');

                    Acquisition::query()
                        ->where('acquisition_paperwork_id', $paperwork->id)
                        ->each(function (Acquisition $acquisition) use ($purchaseOrder, $iar): void {
                            $iarLine = $iar->lines->firstWhere(
                                'acquisition_paperwork_line_id',
                                $acquisition->acquisition_paperwork_line_id,
                            );
                            $poLine = $purchaseOrder->lines->firstWhere(
                                'acquisition_paperwork_line_id',
                                $acquisition->acquisition_paperwork_line_id,
                            );

                            $acquisition->update([
                                'purchase_order_id' => $purchaseOrder->id,
                                'purchase_order_line_id' => $poLine?->id,
                                'inspection_acceptance_report_id' => $iar->id,
                                'inspection_acceptance_report_line_id' => $iarLine?->id,
                            ]);
                        });
                });
            });
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
