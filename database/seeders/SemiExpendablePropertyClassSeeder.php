<?php

namespace Database\Seeders;

use App\Events\RequisitionChanged;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\ItemPropertyClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

class SemiExpendablePropertyClassSeeder extends Seeder
{
    public function run(): void
    {
        Event::fake([RequisitionChanged::class]);

        $category = ItemCategory::firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable properties'],
        );

        $office = Office::query()->firstWhere('code', 'OWWA-IVA')
            ?? Office::factory()->create([
                'code' => 'OWWA-IVA',
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
            ]);

        $department = Department::query()->firstOrCreate(
            [
                'office_id' => $office->id,
                'name' => 'Admin',
            ],
            ['code' => '01'],
        );

        $custodian = User::query()->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->where('office_id', $office->id)
            ->first()
            ?? User::factory()->create([
                'role' => User::ROLE_SUPPLY_CUSTODIAN,
                'office_id' => $office->id,
                'department_id' => $department->id,
            ]);

        $catalog = [
            ItemPropertyClass::Ict => ['code' => 'SEM-ICT-001', 'name' => 'Router — ICT'],
            ItemPropertyClass::OfficeEquipment => ['code' => 'SEM-OE-001', 'name' => 'Printer — Office equipment'],
            ItemPropertyClass::FurnituresFixtures => ['code' => 'SEM-FF-001', 'name' => 'Office chair — Furnitures & fixtures'],
            ItemPropertyClass::SportsEquipment => ['code' => 'SEM-SP-001', 'name' => 'Basketball — Sports equipment'],
            ItemPropertyClass::MedicalEquipment => ['code' => 'SEM-MD-001', 'name' => 'Wheelchair — Medical equipment'],
            ItemPropertyClass::VehicleEquipment => ['code' => 'SEM-VE-001', 'name' => 'Service van tools — Vehicle equipment'],
        ];

        $requisition = Requisition::firstOrCreate(
            ['reference_code' => '2026-07-9000'],
            [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $custodian->id,
                'status' => Requisition::STATUS_ACCEPTED,
            ],
        );

        $issuanceSeq = 1;

        foreach ($catalog as $propertyClass => $spec) {
            $item = Item::updateOrCreate(
                ['item_code' => $spec['code']],
                [
                    'item_category_id' => $category->id,
                    'name' => $spec['name'],
                    'unit' => 'piece',
                    'property_class' => $propertyClass,
                    'reorder_level' => 1,
                ],
            );

            Acquisition::firstOrCreate(
                ['reference_code' => 'ACQ-'.$spec['code']],
                [
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'quantity' => 1,
                    'unit_cost' => 1000,
                    'acquisition_date' => Carbon::parse('2026-07-01'),
                    'recorded_by' => $custodian->id,
                ],
            );

            Issuance::firstOrCreate(
                ['reference_code' => '2026-07-9'.str_pad((string) $issuanceSeq, 3, '0', STR_PAD_LEFT)],
                [
                    'requisition_id' => $requisition->id,
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'issuance_date' => Carbon::parse('2026-07-02'),
                    'issued_by' => $custodian->id,
                    'issued_to' => $custodian->id,
                    'property_number' => $spec['code'].'-001',
                    'estimated_useful_life' => '5 yrs',
                ],
            );

            $issuanceSeq++;
        }
    }
}
