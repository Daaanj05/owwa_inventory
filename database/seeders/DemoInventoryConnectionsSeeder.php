<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Support\DemoInventoryWorkflow;
use App\Support\DemoSemiItemCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoInventoryConnectionsSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA');
        $satellite = Office::query()->firstWhere('code', 'OWWA-LAG');

        if (! $office || ! $satellite) {
            return;
        }

        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->firstOrFail();
        $admin = Department::query()->where('office_id', $office->id)->where('code', 'ADM')->first();

        $semiCategory = ItemCategory::query()->where('name', 'Semi-Expendable')->first();
        $ppeCategory = ItemCategory::query()->where('name', 'Property, Plant and Equipment')->first();

        foreach (DemoSemiItemCatalog::allItemAttributes() as $itemCode => $attrs) {
            Item::query()->where('item_code', $itemCode)->update([
                'property_class' => $attrs['property_class'],
                'estimated_useful_life' => $attrs['estimated_useful_life'],
            ]);
        }

        $stapler = Item::query()->where('item_code', 'SEM-001')->first();
        if ($stapler) {
            Acquisition::query()->firstOrCreate(
                ['reference_code' => 'ACQ-SEM-001-LEGACY'],
                [
                    'item_id' => $stapler->id,
                    'office_id' => $office->id,
                    'quantity' => 3,
                    'unit_cost' => 10000.00,
                    'acquisition_date' => Carbon::parse('2025-06-01'),
                    'source' => 'Legacy procurement — superseded cost',
                    'recorded_by' => $custodian->id,
                ],
            );
        }

        $unitService = app(AcquisitionUnitService::class);

        Acquisition::query()
            ->where('office_id', $office->id)
            ->whereIn('item_id', Item::query()
                ->whereIn('item_category_id', array_filter([$semiCategory?->id, $ppeCategory?->id]))
                ->pluck('id'))
            ->with(['item.category', 'office'])
            ->orderBy('id')
            ->each(function (Acquisition $acquisition) use ($unitService): void {
                $unitService->generateUnitsForAcquisition($acquisition);
            });

        $this->seedPropertyIssuancesViaRequisitions($office, $admin, $custodian);
        $this->enrichTransfersWithUnitCost();
    }

    protected function seedPropertyIssuancesViaRequisitions(
        Office $office,
        ?Department $admin,
        User $custodian,
    ): void {
        if (! $admin) {
            return;
        }

        $workflow = app(DemoInventoryWorkflow::class);

        $catalogReq = Requisition::query()->where('reference_code', 'REQ-DEMO-SEM-CATALOG')->first();
        if ($catalogReq) {
            $workflow->issueAllLines($catalogReq->fresh(['items.item']), $custodian, '2026-05-20');
        }

        $ppeItems = Item::query()
            ->whereIn('item_code', ['PPE-001', 'PPE-002'])
            ->get();

        if ($ppeItems->isNotEmpty()) {
            $ppeReq = $workflow->seedRequisition(
                referenceCode: 'REQ-DEMO-PROP-PPE',
                office: $office,
                department: $admin,
                requestedBy: $custodian,
                lines: $ppeItems->map(fn (Item $item): array => [
                    'item' => $item,
                    'quantity' => 1,
                ])->all(),
                approvedBy: $custodian,
                approvedAt: Carbon::parse('2026-05-24'),
                remarks: 'PAR issuance for export demo',
            );

            $workflow->issueAllLines($ppeReq, $custodian, '2026-05-25');
        }

        $stapler = Item::query()->where('item_code', 'SEM-001')->first();
        if ($stapler) {
            $semiReq = $workflow->seedRequisition(
                referenceCode: 'REQ-DEMO-PROP-SEM-001',
                office: $office,
                department: $admin,
                requestedBy: $custodian,
                lines: [['item' => $stapler, 'quantity' => 1]],
                approvedBy: $custodian,
                approvedAt: Carbon::parse('2026-05-24'),
                remarks: 'ICS issuance — tagged property demo',
            );

            $workflow->issueAllLines($semiReq, $custodian, '2026-05-25');
        }
    }

    protected function enrichTransfersWithUnitCost(): void
    {
        Transfer::query()->whereNull('unit_cost')->each(function (Transfer $transfer): void {
            $cost = Acquisition::query()
                ->where('item_id', $transfer->item_id)
                ->where('office_id', $transfer->from_office_id)
                ->orderByDesc('acquisition_date')
                ->value('unit_cost');

            if ($cost !== null) {
                $transfer->update(['unit_cost' => $cost]);
            }
        });
    }
}
