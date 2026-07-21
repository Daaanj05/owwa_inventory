<?php

namespace Database\Seeders;

use App\Models\Disposal;
use App\Models\DisposalBatch;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DemoDisposalSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA');
        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if (! $office || ! $custodian) {
            return;
        }

        $this->seedConsumableWmrBatch($office, $custodian);
        $this->seedSemiRlsddp($office, $custodian);
        $this->seedSemiIiruspBatch($office, $custodian);
        $this->seedPpeIirupBatch($office, $custodian);
    }

    protected function seedConsumableWmrBatch(Office $office, User $custodian): void
    {
        $lines = [
            ['item_code' => 'CON-003', 'quantity' => 3, 'reason' => 'Expired / dried out', 'wmr_no' => 1, 'sale_amount' => 150.00],
            ['item_code' => 'CON-007', 'quantity' => 2, 'reason' => 'Damaged packaging — unsaleable', 'wmr_no' => 2, 'sale_amount' => 40.00],
        ];

        $batch = $this->upsertBatch('2026-03-0001', [
            'category_slug' => 'consumables',
            'office_id' => $office->id,
            'disposal_date' => Carbon::parse('2026-03-25'),
            'disposal_type' => 'waste_sale',
            'disposal_mode' => 'sold_public',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'accountable_officer_station' => $office->name,
            'approved_by_printed_name' => 'Roberto Cruz',
            'inspection_officer_printed_name' => 'Ana Reyes',
            'witness_printed_name' => 'Maria Santos',
        ]);

        foreach ($lines as $line) {
            $item = Item::query()->where('item_code', $line['item_code'])->first();
            if (! $item) {
                continue;
            }

            Disposal::query()->updateOrCreate(
                [
                    'disposal_batch_id' => $batch->id,
                    'item_id' => $item->id,
                ],
                [
                    'reference_code' => $batch->reference_code,
                    'office_id' => $office->id,
                    'quantity' => $line['quantity'],
                    'disposal_date' => Carbon::parse('2026-03-25'),
                    'reason' => $line['reason'],
                    'disposal_type' => 'waste_sale',
                    'acquisition_cost' => $item->acquisitions()
                        ->where('office_id', $office->id)
                        ->orderByDesc('acquisition_date')
                        ->value('unit_cost'),
                    'place_of_storage' => 'Supply Room A — Shelf 2',
                    'wmr_inspection_item_no' => $line['wmr_no'],
                    'disposal_mode' => 'sold_public',
                    'sale_date' => Carbon::parse('2026-03-26'),
                    'sale_amount' => $line['sale_amount'],
                    'official_receipt_no' => 'OR-2026-0042',
                    'custodian_printed_name' => 'Supply Custodian',
                    'accountable_officer_designation' => 'Supply Officer',
                    'accountable_officer_station' => $office->name,
                    'approved_by_printed_name' => 'Roberto Cruz',
                    'inspection_officer_printed_name' => 'Ana Reyes',
                    'witness_printed_name' => 'Maria Santos',
                    'recorded_by' => $custodian->id,
                ],
            );
        }
    }

    protected function seedSemiRlsddp(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'SEM-004')->first();

        if (! $item) {
            return;
        }

        $unit = $this->availableUnitsForItem($item->id, $office->id)->first();

        if (! $unit) {
            return;
        }

        $issuance = $this->issuanceForUnit($unit, $item->id, $office->id);
        $acquisitionCost = $unit->acquisition?->unit_cost ?? $item->acquisitions()
            ->where('office_id', $office->id)
            ->orderByDesc('acquisition_date')
            ->value('unit_cost');

        $batch = $this->upsertBatch('2026-03-0002', [
            'category_slug' => 'semi_expendable',
            'office_id' => $office->id,
            'department_id' => $issuance?->department_id,
            'disposal_date' => Carbon::parse('2026-03-20'),
            'disposal_type' => 'lost_stolen_damaged',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'accountable_officer_station' => $office->name,
            'immediate_supervisor_printed_name' => 'Roberto Cruz',
            'witness_printed_name' => 'Maria Santos',
        ]);

        Disposal::query()->updateOrCreate(
            [
                'disposal_batch_id' => $batch->id,
                'item_id' => $item->id,
            ],
            [
                'reference_code' => $batch->reference_code,
                'inventory_unit_id' => $unit->id,
                'office_id' => $office->id,
                'department_id' => $issuance?->department_id,
                'quantity' => 1,
                'disposal_date' => Carbon::parse('2026-03-20'),
                'reason' => 'Damaged beyond repair',
                'disposal_type' => 'lost_stolen_damaged',
                'property_number' => $unit->property_number,
                'acquisition_cost' => $acquisitionCost,
                'property_status' => 'damaged',
                'circumstances' => 'Wall clock fell during office renovation; glass shattered and mechanism damaged.',
                'place_of_storage' => 'Administrative Division — Finance area',
                'police_notified' => false,
                'par_issuance_id' => $issuance?->id,
                'custodian_printed_name' => 'Supply Custodian',
                'accountable_officer_designation' => 'Supply Officer',
                'accountable_officer_station' => $office->name,
                'immediate_supervisor_printed_name' => 'Roberto Cruz',
                'witness_printed_name' => 'Maria Santos',
                'recorded_by' => $custodian->id,
            ],
        );
    }

    protected function seedSemiIiruspBatch(Office $office, User $custodian): void
    {
        $specs = [
            ['item_code' => 'SEM-002', 'reason' => 'Unserviceable — warped tray'],
            ['item_code' => 'SEM-005', 'reason' => 'Unserviceable — motor failure'],
        ];

        $resolved = [];
        foreach ($specs as $spec) {
            $item = Item::query()->where('item_code', $spec['item_code'])->first();
            if (! $item) {
                continue;
            }
            $unit = $this->availableUnitsForItem($item->id, $office->id)->first();
            if (! $unit) {
                continue;
            }
            $resolved[] = ['item' => $item, 'unit' => $unit, 'reason' => $spec['reason']];
        }

        if (count($resolved) < 2) {
            return;
        }

        $firstIssuance = $this->issuanceForUnit($resolved[0]['unit'], $resolved[0]['item']->id, $office->id);

        $batch = $this->upsertBatch('2026-03-0004', [
            'category_slug' => 'semi_expendable',
            'office_id' => $office->id,
            'department_id' => $firstIssuance?->department_id,
            'disposal_date' => Carbon::parse('2026-03-28'),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'accountable_officer_station' => $office->name,
            'approved_by_printed_name' => 'Roberto Cruz',
            'inspection_officer_printed_name' => 'Ana Reyes',
            'witness_printed_name' => 'Maria Santos',
        ]);

        foreach ($resolved as $row) {
            /** @var Item $item */
            $item = $row['item'];
            /** @var InventoryUnit $unit */
            $unit = $row['unit'];
            $issuance = $this->issuanceForUnit($unit, $item->id, $office->id);
            $cost = $unit->unit_cost ?? $unit->acquisition?->unit_cost ?? $item->acquisitions()
                ->where('office_id', $office->id)
                ->orderByDesc('acquisition_date')
                ->value('unit_cost');

            Disposal::query()->updateOrCreate(
                [
                    'disposal_batch_id' => $batch->id,
                    'item_id' => $item->id,
                ],
                [
                    'reference_code' => $batch->reference_code,
                    'inventory_unit_id' => $unit->id,
                    'office_id' => $office->id,
                    'department_id' => $issuance?->department_id,
                    'quantity' => 1,
                    'disposal_date' => Carbon::parse('2026-03-28'),
                    'reason' => $row['reason'],
                    'remarks' => 'Export-mapping fixture — inventory side only',
                    'disposal_type' => 'unserviceable',
                    'property_number' => $unit->property_number,
                    'acquisition_cost' => $cost,
                    'accumulated_depreciation' => 0,
                    'accumulated_impairment_losses' => 0,
                    'place_of_storage' => 'Supply Room B — Semi-expendable rack',
                    'par_issuance_id' => $issuance?->id,
                    'custodian_printed_name' => 'Supply Custodian',
                    'accountable_officer_designation' => 'Supply Officer',
                    'accountable_officer_station' => $office->name,
                    'approved_by_printed_name' => 'Roberto Cruz',
                    'inspection_officer_printed_name' => 'Ana Reyes',
                    'witness_printed_name' => 'Maria Santos',
                    'recorded_by' => $custodian->id,
                ],
            );
        }
    }

    protected function seedPpeIirupBatch(Office $office, User $custodian): void
    {
        $specs = [
            ['item_code' => 'PPE-003', 'reason' => 'Unserviceable — paper jam defect'],
            ['item_code' => 'PPE-004', 'reason' => 'Unserviceable — power supply failure'],
        ];

        $resolved = [];
        foreach ($specs as $spec) {
            $item = Item::query()->where('item_code', $spec['item_code'])->first();
            if (! $item) {
                continue;
            }
            $unit = $this->availableUnitsForItem($item->id, $office->id)->first();
            if (! $unit) {
                continue;
            }
            $resolved[] = ['item' => $item, 'unit' => $unit, 'reason' => $spec['reason']];
        }

        if ($resolved === []) {
            return;
        }

        $firstIssuance = $this->issuanceForUnit($resolved[0]['unit'], $resolved[0]['item']->id, $office->id);

        $batch = $this->upsertBatch('2026-03-0003', [
            'category_slug' => 'ppe',
            'office_id' => $office->id,
            'department_id' => $firstIssuance?->department_id,
            'disposal_date' => Carbon::parse('2026-03-15'),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'accountable_officer_station' => $office->name,
            'approved_by_printed_name' => 'Roberto Cruz',
            'inspection_officer_printed_name' => 'Ana Reyes',
            'witness_printed_name' => 'Maria Santos',
        ]);

        foreach ($resolved as $index => $row) {
            /** @var Item $item */
            $item = $row['item'];
            /** @var InventoryUnit $unit */
            $unit = $row['unit'];
            $issuance = $this->issuanceForUnit($unit, $item->id, $office->id);
            $cost = (float) ($unit->unit_cost ?? $unit->acquisition?->unit_cost ?? 55000.00);

            Disposal::query()->updateOrCreate(
                [
                    'disposal_batch_id' => $batch->id,
                    'item_id' => $item->id,
                ],
                [
                    'reference_code' => $batch->reference_code,
                    'inventory_unit_id' => $unit->id,
                    'office_id' => $office->id,
                    'department_id' => $issuance?->department_id,
                    'quantity' => 1,
                    'disposal_date' => Carbon::parse('2026-03-15'),
                    'reason' => $row['reason'],
                    'disposal_type' => 'unserviceable',
                    'property_number' => $unit->property_number,
                    'acquisition_cost' => $cost,
                    'accumulated_depreciation' => $index === 0 ? 5000 : 2500,
                    'accumulated_impairment_losses' => 0,
                    'iirup_disposal_mode' => $index === 0 ? 'transfer' : 'destruction',
                    'iirup_disposal_amount' => $index === 0 ? 500.00 : null,
                    'appraised_value' => $index === 0 ? 800.00 : 200.00,
                    'place_of_storage' => 'IT Room — Equipment bay',
                    'par_issuance_id' => $issuance?->id,
                    'custodian_printed_name' => 'Supply Custodian',
                    'accountable_officer_designation' => 'Supply Officer',
                    'accountable_officer_station' => $office->name,
                    'approved_by_printed_name' => 'Roberto Cruz',
                    'authorized_official_designation' => 'Regional Director',
                    'inspection_officer_printed_name' => 'Ana Reyes',
                    'witness_printed_name' => 'Maria Santos',
                    'recorded_by' => $custodian->id,
                ],
            );
        }
    }

    /**
     * @return Collection<int, InventoryUnit>
     */
    protected function availableUnitsForItem(int $itemId, int $officeId): Collection
    {
        $usedUnitIds = Disposal::query()
            ->whereNotNull('inventory_unit_id')
            ->pluck('inventory_unit_id');

        return InventoryUnit::query()
            ->with('acquisition')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->whereIn('status', [InventoryUnit::STATUS_IN_STOCK, InventoryUnit::STATUS_ISSUED])
            ->when($usedUnitIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $usedUnitIds))
            ->orderBy('id')
            ->get();
    }

    protected function issuanceForUnit(InventoryUnit $unit, int $itemId, int $officeId): ?Issuance
    {
        if ($unit->issuance_id) {
            return Issuance::query()->find($unit->issuance_id);
        }

        return Issuance::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertBatch(string $referenceCode, array $attributes): DisposalBatch
    {
        return DisposalBatch::query()->updateOrCreate(
            ['reference_code' => $referenceCode],
            $attributes,
        );
    }
}
