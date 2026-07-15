<?php

namespace Tests\Unit;

use App\Models\ItemCategory;
use App\Support\InventoryCategoryOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCategoryOptionsTest extends TestCase
{
    use RefreshDatabase;

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
}
