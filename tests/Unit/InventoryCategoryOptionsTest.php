<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\InventoryStockService;
use App\Support\InventoryCategoryOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryCategoryOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        InventoryCategoryOptions::forgetCache();
    }

    public function test_active_category_options_follow_fixed_navigation_order(): void
    {
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment (PPE)'],
        );
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);

        $orderedIds = array_keys(InventoryCategoryOptions::allActiveCategoryOptions());

        $this->assertSame(
            [$consumables->id, $semi->id, $ppe->id],
            $orderedIds,
        );
    }

    public function test_property_category_options_keep_semi_before_ppe(): void
    {
        ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment (PPE)'],
        );

        $orderedIds = array_keys(InventoryCategoryOptions::propertyCategoryOptions());

        $this->assertSame([$semi->id, $ppe->id], $orderedIds);
    }

    public function test_category_options_are_cached_and_invalidated_on_save(): void
    {
        ItemCategory::factory()->create(['name' => 'Consumables']);

        $first = InventoryCategoryOptions::allActiveCategoryOptions();
        $this->assertTrue(Cache::has(InventoryCategoryOptions::CACHE_KEY));
        $this->assertSame($first, InventoryCategoryOptions::allActiveCategoryOptions());

        ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $second = InventoryCategoryOptions::allActiveCategoryOptions();
        $this->assertCount(count($first) + 1, $second);
        $this->assertContains('Semi-Expendable', array_values($second));
    }

    public function test_movement_totals_cache_is_reused_until_forgotten(): void
    {
        $service = app(InventoryStockService::class);
        $service->forgetMovementTotalsCache();

        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-CACHE-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 5.0,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(10, $service->getStock($item->id, $office->id));

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-CACHE-2-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 5.0,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(10, $service->getStock($item->id, $office->id));

        $service->forgetMovementTotalsCache();

        $this->assertSame(15, $service->getStock($item->id, $office->id));
    }
}
