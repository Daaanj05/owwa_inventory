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

class DemoDisposalSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA');
        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if (! $office || ! $custodian) {
            return;
        }

        $this->seedConsumableWmr($office, $custodian);
        $this->seedSemiRlsddp($office, $custodian);
        $this->seedPpeIirup($office, $custodian);
    }

    protected function seedConsumableWmr(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'CON-003')->first();

        if (! $item) {
            return;
        }

        $batch = $this->upsertBatch('2026-03-0001', [
            'category_slug' => 'consumables',
            'office_id' => $office->id,
            'disposal_date' => Carbon::parse('2026-03-25'),
            'disposal_type' => 'waste_sale',
            'disposal_mode' => 'sold_public',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'approved_by_printed_name' => 'Roberto Cruz',
            'inspection_officer_printed_name' => 'Ana Reyes',
        ]);

        Disposal::query()->updateOrCreate(
            [
                'disposal_batch_id' => $batch->id,
                'item_id' => $item->id,
            ],
            [
                'reference_code' => $batch->reference_code,
                'office_id' => $office->id,
                'quantity' => 3,
                'disposal_date' => Carbon::parse('2026-03-25'),
                'reason' => 'Expired / dried out',
                'disposal_type' => 'waste_sale',
                'acquisition_cost' => $item->acquisitions()
                    ->where('office_id', $office->id)
                    ->orderByDesc('acquisition_date')
                    ->value('unit_cost'),
                'place_of_storage' => 'Supply Room A — Shelf 2',
                'wmr_inspection_item_no' => 1,
                'disposal_mode' => 'sold_public',
                'sale_date' => Carbon::parse('2026-03-26'),
                'sale_amount' => 150.00,
                'official_receipt_no' => 'OR-2026-0042',
                'custodian_printed_name' => 'Supply Custodian',
                'accountable_officer_designation' => 'Supply Officer',
                'approved_by_printed_name' => 'Roberto Cruz',
                'inspection_officer_printed_name' => 'Ana Reyes',
                'recorded_by' => $custodian->id,
            ],
        );
    }

    protected function seedSemiRlsddp(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'SEM-004')->first();

        if (! $item) {
            return;
        }

        $unit = InventoryUnit::query()
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->whereIn('status', [InventoryUnit::STATUS_IN_STOCK, InventoryUnit::STATUS_ISSUED])
            ->orderBy('id')
            ->first();

        if (! $unit) {
            return;
        }

        $issuance = $unit->issuance_id
            ? Issuance::query()->find($unit->issuance_id)
            : Issuance::query()
                ->where('item_id', $item->id)
                ->where('office_id', $office->id)
                ->orderByDesc('id')
                ->first();

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
                'immediate_supervisor_printed_name' => 'Roberto Cruz',
                'witness_printed_name' => 'Maria Santos',
                'recorded_by' => $custodian->id,
            ],
        );
    }

    protected function seedPpeIirup(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'PPE-003')->first();

        if (! $item) {
            return;
        }

        $unit = InventoryUnit::query()
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->whereIn('status', [InventoryUnit::STATUS_IN_STOCK, InventoryUnit::STATUS_ISSUED])
            ->orderBy('id')
            ->first();

        if (! $unit) {
            return;
        }

        $issuance = $unit->issuance_id
            ? Issuance::query()->find($unit->issuance_id)
            : Issuance::query()
                ->where('item_id', $item->id)
                ->where('office_id', $office->id)
                ->orderByDesc('id')
                ->first();

        $acquisitionCost = $unit->acquisition?->unit_cost ?? 55000.00;

        $batch = $this->upsertBatch('2026-03-0003', [
            'category_slug' => 'ppe',
            'office_id' => $office->id,
            'department_id' => $issuance?->department_id,
            'disposal_date' => Carbon::parse('2026-03-15'),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'accountable_officer_designation' => 'Supply Officer',
            'approved_by_printed_name' => 'Roberto Cruz',
            'inspection_officer_printed_name' => 'Ana Reyes',
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
                'disposal_date' => Carbon::parse('2026-03-15'),
                'reason' => 'Unserviceable — paper jam defect',
                'disposal_type' => 'unserviceable',
                'property_number' => $unit->property_number,
                'acquisition_cost' => $acquisitionCost,
                'iirup_disposal_mode' => 'transfer',
                'place_of_storage' => 'IT Room — Printer bay',
                'par_issuance_id' => $issuance?->id,
                'custodian_printed_name' => 'Supply Custodian',
                'accountable_officer_designation' => 'Supply Officer',
                'approved_by_printed_name' => 'Roberto Cruz',
                'inspection_officer_printed_name' => 'Ana Reyes',
                'recorded_by' => $custodian->id,
            ],
        );
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
