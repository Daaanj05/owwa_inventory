<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendablePropertyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_semi_issuance_reuses_finalized_inventory_item_number(): void
    {
        $office = Office::factory()->create([
            'code' => '01',
            'is_regional_supply' => true,
            'is_satellite' => false,
        ]);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Placeholder',
            'is_active' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::InformationTechnology,
            'uacs_object_code_id' => $uacs->id,
        ]);
        $custodian = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-200',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 8000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $expected = 'SPHV-'.now()->format('Y').'-IT-106-001-01';
        $this->assertSame($expected, $item->fresh()->semi_expendable_property_number);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0099',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => 'pending',
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 8000,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
        ]);

        $this->assertSame($expected, (string) $issuance->fresh()->property_number);
    }

    public function test_acquisition_syncs_item_value_type(): void
    {
        $office = Office::factory()->create([
            'code' => 'RWO4A',
            'is_regional_supply' => true,
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Placeholder',
            'is_active' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'value_type' => 'low',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);
        $custodian = User::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-201',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 8000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->assertSame('high', $item->fresh()->value_type);
    }
}
