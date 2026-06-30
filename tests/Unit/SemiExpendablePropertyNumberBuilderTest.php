<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyNumberBucket;
use App\Models\User;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendablePropertyNumberBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_produces_sample_format(): void
    {
        $office = Office::factory()->create(['code' => 'RWO4A']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::Ict,
            'value_type' => 'low',
        ]);
        $custodian = User::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-100',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'acquisition_date' => '2024-06-01',
            'recorded_by' => $custodian->id,
        ]);

        $issuance = new Issuance([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'unit_cost' => 4500,
            'issuance_date' => '2024-06-15',
        ]);

        $number = app(SemiExpendablePropertyNumberBuilder::class)->assignForIssuance($issuance);

        $this->assertSame('SPLV-2024-ICT-106-01-001', $number);
        $this->assertDatabaseHas(PropertyNumberBucket::class, [
            'bucket_key' => 'SPLV|2024|ICT|106|01',
            'next_sequence' => 2,
        ]);
    }
}
