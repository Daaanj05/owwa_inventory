<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryStockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryStockService();
    }

    public function test_get_stock_returns_zero_when_no_transactions(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $stock = $this->service->getStock($item->id, $office->id);

        $this->assertSame(0, $stock);
    }

    public function test_is_low_stock_returns_true_when_stock_at_or_below_reorder_level(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $office = Office::factory()->create();

        $this->assertTrue($this->service->isLowStock($item, $office->id));
    }
}
