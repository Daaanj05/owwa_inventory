<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\UacsObjectCode;
use App\Services\CatalogAssetNumberService;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAssetNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_semi_item_gets_provisional_temp_number_at_register(): void
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

        $this->assertSame(
            'TEMP-'.now()->format('Y').'-IT-106-001-RWO4A',
            $item->fresh()->semi_expendable_property_number,
        );
    }

    public function test_finalize_semi_replaces_temp_with_splv_or_sphv(): void
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

        $service = app(CatalogAssetNumberService::class);

        $low = $service->finalizeSemiWithUnitCost($item->fresh(), 4500);
        $this->assertSame('SPLV-'.now()->format('Y').'-IT-106-001-RWO4A', $low);

        $item->forceFill([
            'semi_expendable_property_number' => 'TEMP-'.now()->format('Y').'-IT-106-002-RWO4A',
        ])->saveQuietly();

        $high = $service->finalizeSemiWithUnitCost($item->fresh(), 8000);
        $this->assertSame('SPHV-'.now()->format('Y').'-IT-106-002-RWO4A', $high);
    }

    public function test_ppe_item_gets_property_number_at_register(): void
    {
        Office::factory()->create([
            'code' => 'RWO4A',
            'is_regional_supply' => true,
            'is_satellite' => false,
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106-03',
            'name' => 'Office Equipment',
            'is_active' => true,
        ]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);

        $this->assertSame(
            now()->format('Y').'-OE-106-03-001-RWO4A',
            $item->fresh()->ppe_property_number,
        );
    }
}
