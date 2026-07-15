<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendablePropertyNumberBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_finalizes_temp_inventory_item_number(): void
    {
        Office::factory()->create([
            'code' => 'RWO4A',
            'is_regional_supply' => true,
            'is_satellite' => false,
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
        $user = User::factory()->create();

        $this->assertStringStartsWith('TEMP-', (string) $item->semi_expendable_property_number);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-SEMI-1',
            'item_id' => $item->id,
            'office_id' => Office::query()->first()->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'acquisition_date' => '2024-06-15',
            'recorded_by' => $user->id,
        ]);

        $number = app(SemiExpendablePropertyNumberBuilder::class)->assignForAcquisition($acquisition->fresh(['item.category', 'office']));

        $this->assertSame('SPLV-'.now()->format('Y').'-IT-106-001-RWO4A', $number);
        $this->assertSame($number, $item->fresh()->semi_expendable_property_number);
    }

    public function test_ppe_units_reuse_item_property_number(): void
    {
        Office::factory()->create([
            'code' => 'OWWAIVA',
            'is_regional_supply' => true,
            'is_satellite' => false,
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Placeholder',
            'is_active' => true,
        ]);
        $ppeCategory = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $user = User::factory()->create();

        $ppeItem = Item::factory()->create([
            'item_category_id' => $ppeCategory->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);

        $propertyNumber = $ppeItem->fresh()->ppe_property_number;
        $this->assertNotEmpty($propertyNumber);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-PPE-1',
            'item_id' => $ppeItem->id,
            'office_id' => Office::query()->first()->id,
            'quantity' => 2,
            'unit_cost' => 60000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $units = app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $this->assertCount(2, $units);
        $this->assertSame($propertyNumber, $units[0]->property_number);
        $this->assertSame($propertyNumber, $units[1]->property_number);
    }
}
