<?php

namespace Tests\Support;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\ItemPropertyClass;

trait CreatesSemiExpendableAnnexA4Fixtures
{
    /**
     * @return array{
     *     category: ItemCategory,
     *     office: Office,
     *     department: Department,
     *     custodian: User,
     *     item: Item,
     *     issuance: Issuance,
     * }
     */
    protected function createSemiItemWithIssuance(string $propertyClass, ?string $itemName = null): array
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $factory = Item::factory()->for($category, 'category');

        $itemFactory = match ($propertyClass) {
            ItemPropertyClass::Ict => $factory->ict(),
            ItemPropertyClass::OfficeEquipment => $factory->officeEquipment(),
            ItemPropertyClass::FurnituresFixtures => $factory->furnituresFixtures(),
            ItemPropertyClass::SportsEquipment => $factory->sportsEquipment(),
            ItemPropertyClass::MedicalEquipment => $factory->medicalEquipment(),
            ItemPropertyClass::VehicleEquipment => $factory->vehicleEquipment(),
            default => $factory->officeEquipment(),
        };

        $item = $itemFactory->create([
            'name' => $itemName ?? 'Semi item '.$propertyClass,
            'item_code' => 'SEM-'.strtoupper(substr($propertyClass, 0, 3)).'-'.fake()->unique()->numberBetween(100, 999),
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT),
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-02-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT),
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'property_number' => $item->item_code.'-001',
            'estimated_useful_life' => '5 yrs',
        ]);

        return [
            'category' => $category,
            'office' => $office,
            'department' => $department,
            'custodian' => $custodian,
            'item' => $item,
            'issuance' => $issuance,
        ];
    }
}
