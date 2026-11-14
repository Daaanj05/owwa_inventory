<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\InventoryCategoryUnitCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCategoryUnitCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumables_accept_any_positive_unit_cost(): void
    {
        $item = $this->itemInCategory('Consumables');

        InventoryCategoryUnitCost::assertForItem($item, 1);
        InventoryCategoryUnitCost::assertForItem($item, 49999.99);
        InventoryCategoryUnitCost::assertForItem($item, 50000);
        InventoryCategoryUnitCost::assertForItem($item, 100000);

        $this->assertFalse(InventoryCategoryUnitCost::isOutOfRangeForItem($item, 100000));
    }

    public function test_semi_expendable_rejects_cap_and_above(): void
    {
        $item = $this->itemInCategory('Semi-Expendable');

        InventoryCategoryUnitCost::assertForItem($item, 49999.99);
        $this->assertFalse(InventoryCategoryUnitCost::isOutOfRangeForItem($item, 49999.99));

        $this->expectException(ValidationException::class);
        InventoryCategoryUnitCost::assertForItem($item, 50000);
    }

    public function test_ppe_rejects_below_minimum(): void
    {
        $item = $this->itemInCategory('PPE');

        InventoryCategoryUnitCost::assertForItem($item, 50000);
        $this->assertFalse(InventoryCategoryUnitCost::isOutOfRangeForItem($item, 50000));

        $this->expectException(ValidationException::class);
        InventoryCategoryUnitCost::assertForItem($item, 49999.99);
    }

    public function test_line_states_detect_out_of_range_semi_cost(): void
    {
        $item = $this->itemInCategory('Semi-Expendable');

        $this->assertTrue(InventoryCategoryUnitCost::lineStatesHaveOutOfRangeCost([
            [
                'item_id' => $item->id,
                'is_ordered' => true,
                'po_quantity' => 2,
                'unit_cost' => 50000,
            ],
        ]));

        $this->assertFalse(InventoryCategoryUnitCost::lineStatesHaveOutOfRangeCost([
            [
                'item_id' => $item->id,
                'is_ordered' => true,
                'po_quantity' => 2,
                'unit_cost' => 12000,
            ],
        ]));
    }

    protected function itemInCategory(string $categoryName): Item
    {
        $category = ItemCategory::factory()->create(['name' => $categoryName]);

        return Item::factory()->create(['item_category_id' => $category->id]);
    }
}
