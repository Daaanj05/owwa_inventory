<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendablePropertyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_semi_issuance_assigns_composite_property_number(): void
    {
        $office = Office::factory()->create(['code' => '01']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::Ict,
        ]);
        $custodian = User::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-200',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 8000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

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
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
        ]);

        $this->assertStringStartsWith('SPHV-', (string) $issuance->property_number);
        $this->assertSame(
            'SPHV-'.now()->format('Y').'-ICT-106-01-001',
            (string) $issuance->fresh()->property_number,
        );
        $this->assertSame('high', $item->fresh()->value_type);
    }

    public function test_acquisition_syncs_item_value_type(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'value_type' => 'low']);
        $custodian = User::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-300',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 3000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->assertSame('low', $item->fresh()->value_type);
    }
}
