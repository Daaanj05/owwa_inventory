<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\OwwaTransactionViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaTransactionViewPresenterItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_hero_puts_reorder_point_with_stock_category_and_unit(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => '2in1 Dual Wash Mitt Orange',
            'unit' => 'piece',
            'reorder_level' => 0,
        ]);

        $hero = OwwaTransactionViewPresenter::forItem($item->load('category'));

        $this->assertSame('Item', $hero['referenceLabel']);
        $this->assertSame(
            ['Category', 'Unit', 'Reorder point'],
            array_column(array_slice($hero['meta'], 1), 'label'),
        );
        $this->assertSame('0', $hero['meta'][3]['value']);
        $this->assertSame([], $hero['kpis']);
    }
}
