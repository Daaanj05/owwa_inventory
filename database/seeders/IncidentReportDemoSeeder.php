<?php

namespace Database\Seeders;

use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class IncidentReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA');
        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if (! $office || ! $custodian) {
            return;
        }

        $this->seedSemiIncident($office, $custodian);
        $this->seedPpeIncident($office, $custodian);
    }

    protected function seedSemiIncident(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'SEM-SP-001')->first();

        if (! $item) {
            return;
        }

        $unit = $this->resolveIssuedUnit($item, $office);

        if (! $unit) {
            return;
        }

        $issuance = $this->resolveIssuance($unit, $item, $office);
        $cost = (float) ($unit->acquisition?->unit_cost ?? 4500.00);

        Disposal::query()->updateOrCreate(
            ['reference_code' => '2026-07-0001'],
            [
                'item_id' => $item->id,
                'inventory_unit_id' => $unit->id,
                'office_id' => $office->id,
                'department_id' => $issuance?->department_id,
                'quantity' => 1,
                'disposal_date' => Carbon::parse('2026-07-10'),
                'reason' => 'Lost during regional sports event',
                'disposal_type' => 'lost_stolen_damaged',
                'property_number' => $unit->property_number,
                'acquisition_cost' => $cost,
                'property_status' => 'lost',
                'circumstances' => 'Basketball equipment not returned after OWWA sports clinic at Laguna satellite office.',
                'place_of_storage' => 'Operations Division — Sports equipment locker',
                'police_notified' => true,
                'police_station' => 'PNP Sta. Cruz, Laguna',
                'police_notified_date' => Carbon::parse('2026-07-08'),
                'par_issuance_id' => $issuance?->id,
                'custodian_printed_name' => 'Supply Custodian',
                'accountable_officer_designation' => 'Supply Officer',
                'accountable_officer_station' => 'OWWA Regional Office IV-A',
                'immediate_supervisor_printed_name' => 'Roberto Cruz',
                'witness_printed_name' => 'Juan Dela Cruz',
                'gov_id_type' => 'PhilID',
                'gov_id_no' => '1234-5678-9012',
                'gov_id_date_issued' => Carbon::parse('2024-01-15'),
                'recorded_by' => $custodian->id,
            ],
        );
    }

    protected function seedPpeIncident(Office $office, User $custodian): void
    {
        $item = Item::query()->where('item_code', 'PPE-004')->first();

        if (! $item) {
            return;
        }

        $unit = $this->resolveIssuedUnit($item, $office);

        if (! $unit) {
            return;
        }

        $issuance = $this->resolveIssuance($unit, $item, $office);
        $cost = (float) ($unit->acquisition?->unit_cost ?? 55000.00);

        Disposal::query()->updateOrCreate(
            ['reference_code' => '2026-07-0002'],
            [
                'item_id' => $item->id,
                'inventory_unit_id' => $unit->id,
                'office_id' => $office->id,
                'department_id' => $issuance?->department_id,
                'quantity' => 1,
                'disposal_date' => Carbon::parse('2026-07-12'),
                'reason' => 'Damaged by power surge',
                'disposal_type' => 'lost_stolen_damaged',
                'property_number' => $unit->property_number,
                'acquisition_cost' => $cost,
                'property_status' => 'damaged',
                'circumstances' => 'Air conditioning compressor failed after electrical surge; beyond economical repair.',
                'place_of_storage' => 'Administrative Division — Server room adjacent',
                'police_notified' => false,
                'par_issuance_id' => $issuance?->id,
                'custodian_printed_name' => 'Supply Custodian',
                'accountable_officer_designation' => 'Supply Officer',
                'approved_by_printed_name' => 'Roberto Cruz',
                'inspection_officer_printed_name' => 'Ana Reyes',
                'recorded_by' => $custodian->id,
            ],
        );
    }

    protected function resolveIssuedUnit(Item $item, Office $office): ?InventoryUnit
    {
        return InventoryUnit::query()
            ->with('acquisition')
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->whereIn('status', [InventoryUnit::STATUS_ISSUED, InventoryUnit::STATUS_IN_STOCK])
            ->whereNotIn('id', Disposal::query()->whereNotNull('inventory_unit_id')->pluck('inventory_unit_id'))
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveIssuance(InventoryUnit $unit, Item $item, Office $office): ?Issuance
    {
        if ($unit->issuance_id) {
            return Issuance::query()->find($unit->issuance_id);
        }

        return Issuance::query()
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->orderByDesc('id')
            ->first();
    }
}
