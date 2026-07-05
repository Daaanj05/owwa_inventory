<?php

namespace Database\Seeders;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
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
            ->whereIn('item_code', DemoStockLedgerCatalog::allCoreItemCodes())
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
            [
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
            ],
        );

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

        $service->submitPo($paperwork->fresh(['lines.item']));

        if (($spec['in_progress_stop'] ?? null) === 'po_submitted') {
            return;
        }

        $service->approvePo($paperwork->fresh(['lines.item']));
        $service->submitIar($paperwork->fresh(['lines.item']));
        $service->approveIar($paperwork->fresh(['lines.item']));

        if ($spec['received']) {
            $service->recordCustodyReceipts($paperwork->fresh(['lines.item']));
        }
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
