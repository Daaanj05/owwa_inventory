<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use App\Support\DemoAcquisitionPaperworkCatalog;
use App\Support\DemoStockLedgerCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AcquisitionPaperworkDemoSeeder extends Seeder
{
    public function run(): void
    {
        $offices = Office::query()
            ->whereIn('code', ['OWWA-IVA', 'OWWA-LAG'])
            ->get()
            ->keyBy('code');

        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if ($offices->count() < 2 || ! $custodian) {
            return;
        }

        $categories = ItemCategory::query()
            ->whereIn('name', [
                DemoAcquisitionPaperworkCatalog::CATEGORY_CONSUMABLES,
                DemoAcquisitionPaperworkCatalog::CATEGORY_SEMI,
                DemoAcquisitionPaperworkCatalog::CATEGORY_PPE,
            ])
            ->get()
            ->keyBy('name');

        $items = Item::query()
            ->whereIn('item_code', array_merge(
                DemoStockLedgerCatalog::allCoreItemCodes(),
                DemoStockLedgerCatalog::variantConsumableCodes(),
                DemoStockLedgerCatalog::variantPpeCodes(),
            ))
            ->get()
            ->keyBy('item_code');

        $department = Department::query()
            ->where('office_id', $offices['OWWA-IVA']->id)
            ->where('code', 'ADM')
            ->first();

        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->actingAs($custodian, function () use (
            $service,
            $offices,
            $categories,
            $items,
            $department,
            $custodian,
        ): void {
            foreach (DemoAcquisitionPaperworkCatalog::cases() as $spec) {
                $this->seedPaperworkCase(
                    $service,
                    $spec,
                    $offices,
                    $categories,
                    $items,
                    $department,
                    $custodian,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  \Illuminate\Support\Collection<string, Office>  $offices
     * @param  \Illuminate\Support\Collection<string, ItemCategory>  $categories
     * @param  \Illuminate\Support\Collection<string, Item>  $items
     */
    protected function seedPaperworkCase(
        AcquisitionPaperworkCompletionService $service,
        array $spec,
        $offices,
        $categories,
        $items,
        ?Department $department,
        User $custodian,
    ): void {
        $office = $offices[$spec['office_code']] ?? null;
        $requestingOffice = $offices[$spec['requesting_office_code']] ?? null;
        $category = $categories[$spec['category']] ?? null;

        if (! $office || ! $requestingOffice || ! $category) {
            return;
        }

        $paperwork = AcquisitionPaperwork::query()->updateOrCreate(
            ['reference_code' => $spec['reference_code']],
            array_merge($this->demoWorkflowResetAttributes(), [
                'office_id' => $office->id,
                'requesting_office_id' => $requestingOffice->id,
                'department_id' => $department?->id,
                'item_category_id' => $category->id,
                'recorded_by' => $custodian->id,
                'purpose' => $spec['purpose'],
                'pr_date' => Carbon::parse($spec['pr_date']),
                'po_date' => Carbon::parse($spec['po_date']),
                'iar_date' => Carbon::parse($spec['iar_date']),
                'supplier' => 'PS-DBM / Authorized Supplier',
                'requested_by_name' => 'Maria Santos',
                'approved_by_name' => 'Roberto Cruz',
                'inspection_officer_name' => 'Ana Reyes',
                'custodian_name' => 'Supply Custodian',
            ]),
        );

        Acquisition::query()
            ->where('acquisition_paperwork_id', $paperwork->id)
            ->delete();

        foreach ($spec['lines'] as $lineSpec) {
            $item = $items[$lineSpec['item_code']] ?? null;

            if (! $item) {
                continue;
            }

            AcquisitionPaperworkLine::query()->updateOrCreate(
                [
                    'acquisition_paperwork_id' => $paperwork->id,
                    'item_id' => $item->id,
                ],
                [
                    'description' => $item->name,
                    'unit' => $item->unit ?? 'piece',
                    'quantity' => $lineSpec['quantity'],
                    'unit_cost' => $lineSpec['unit_cost'],
                    'amount' => round($lineSpec['quantity'] * $lineSpec['unit_cost'], 2),
                ],
            );
        }

        $paperwork = $paperwork->fresh(['lines.item']);

        $service->submitPr($paperwork);
        $service->approvePr($paperwork->fresh(['lines.item']));

        if (($spec['in_progress_stop'] ?? null) === 'pr_draft') {
            return;
        }

        $paperwork = $paperwork->fresh(['lines.item']);
        $poService = app(PurchaseOrderWorkflowService::class);
        $po = $paperwork->purchaseOrder ?? $poService->createFromApprovedPr($paperwork);
        $po->update([
            'supplier_name' => (string) ($spec['supplier'] ?? $paperwork->supplier ?? 'PS-DBM / Authorized Supplier'),
            'supplier_address' => 'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => $paperwork->office?->name ?? 'OWWA Regional Office IV-A',
            'delivery_term' => 'FOB Destination',
            'technical_specifications' => 'As per PR line items',
            'po_date' => $paperwork->po_date?->toDateString() ?? now()->toDateString(),
        ]);
        $po->lines()->each(function ($line): void {
            $line->update([
                'is_ordered' => true,
                'po_quantity' => (int) $line->pr_quantity,
                'amount' => round((int) $line->pr_quantity * (float) $line->unit_cost, 2),
            ]);
        });

        $service->submitPo($paperwork->fresh(['purchaseOrder.lines']));

        if (($spec['in_progress_stop'] ?? null) === 'po_submitted') {
            return;
        }

        $paperwork = $paperwork->fresh(['purchaseOrder']);
        $service->approvePo($paperwork);

        $po = $paperwork->fresh(['purchaseOrder'])?->purchaseOrder;
        if ($po === null) {
            return;
        }

        $iarService = app(InspectionAcceptanceReportWorkflowService::class);
        $iar = $po->inspectionAcceptanceReport ?? $iarService->createFromApprovedPo($po->fresh());
        $iarDate = Carbon::parse($spec['iar_date'] ?? $paperwork->iar_date ?? now())->startOfDay();
        $iar->update([
            'invoice_number' => 'INV'.preg_replace('/\D+/', '', (string) ($spec['reference_code'] ?? $paperwork->id)).$paperwork->id,
            'invoice_date' => $iarDate->copy()->addDay()->toDateString(),
            'date_inspected' => $iarDate->copy()->addDays(2)->toDateString(),
            'date_received' => $iarDate->copy()->addDays(3)->toDateString(),
            'inspection_officer_name' => 'Ana Reyes',
            'custodian_name' => 'Supply Custodian',
            'iar_date' => $iarDate->toDateString(),
        ]);

        $service->submitIar($paperwork->fresh(['purchaseOrder.inspectionAcceptanceReport']));
        $service->approveIar($paperwork->fresh(['purchaseOrder.inspectionAcceptanceReport']));

        if ($spec['received']) {
            $service->recordCustodyReceipts($paperwork->fresh(['lines.item', 'purchaseOrder.inspectionAcceptanceReport']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function demoWorkflowResetAttributes(): array
    {
        return [
            'phase' => AcquisitionPaperwork::PHASE_PR,
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'pr_number' => null,
            'po_number' => null,
            'iar_number' => null,
            'pr_submitted_at' => null,
            'po_submitted_at' => null,
            'iar_submitted_at' => null,
            'pr_completed_at' => null,
            'po_completed_at' => null,
            'iar_completed_at' => null,
            'received_at' => null,
        ];
    }

    /**
     * @param  callable(callable): void  $callback
     */
    protected function actingAs(User $user, callable $callback): void
    {
        $previous = auth()->user();
        auth()->login($user);

        try {
            $callback();
        } finally {
            if ($previous !== null) {
                auth()->login($previous);
            } else {
                auth()->logout();
            }
        }
    }
}
