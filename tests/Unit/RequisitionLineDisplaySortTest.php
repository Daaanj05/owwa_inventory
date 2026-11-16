<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\RequisitionItem;
use App\Support\RequisitionLineDisplay;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RequisitionLineDisplaySortTest extends TestCase
{
    public function test_stock_number_sort_key_reads_year_then_sequence(): void
    {
        $this->assertSame([2025, 12, 'CON-2025-0012'], RequisitionLineDisplay::stockNumberSortKey('CON-2025-0012'));
        $this->assertSame([2026, 1, 'CON-2026-0001'], RequisitionLineDisplay::stockNumberSortKey('CON-2026-0001'));
        $this->assertSame([PHP_INT_MAX, PHP_INT_MAX, ''], RequisitionLineDisplay::stockNumberSortKey(null));
        $this->assertSame([PHP_INT_MAX, PHP_INT_MAX, 'ABC'], RequisitionLineDisplay::stockNumberSortKey('ABC'));
    }

    public function test_compare_stock_number_keys_orders_by_year_then_sequence(): void
    {
        $this->assertTrue(RequisitionLineDisplay::compareStockNumberKeys('CON-2025-0009', 'CON-2026-0001') < 0);
        $this->assertTrue(RequisitionLineDisplay::compareStockNumberKeys('CON-2026-0001', 'CON-2026-0002') < 0);
        $this->assertTrue(RequisitionLineDisplay::compareStockNumberKeys(null, 'CON-2026-0001') > 0);
    }

    public function test_sort_lines_for_display_orders_category_item_then_stock_number(): void
    {
        $consumables = $this->fakeCategory('Consumables');
        $semi = $this->fakeCategory('Semi-Expendable');
        $ppe = $this->fakeCategory('Property, Plant and Equipment');

        $lines = Collection::make([
            $this->fakeLine($ppe, 'Chair', 'PPE-2026-0002'),
            $this->fakeLine($consumables, 'Bond paper', 'CON-2026-0002'),
            $this->fakeLine($consumables, 'Bond paper', 'CON-2025-0009'),
            $this->fakeLine($semi, 'Desk', 'SE-2026-0001'),
            $this->fakeLine($consumables, 'Alcohol', 'CON-2026-0001'),
        ]);

        $sorted = RequisitionLineDisplay::sortLinesForDisplay($lines)
            ->map(fn (RequisitionItem $line): array => [
                $line->item?->category?->getTemplateSlug(),
                $line->item?->name,
                $line->item?->item_code,
            ])
            ->all();

        $this->assertSame([
            ['consumables', 'Alcohol', 'CON-2026-0001'],
            ['consumables', 'Bond paper', 'CON-2025-0009'],
            ['consumables', 'Bond paper', 'CON-2026-0002'],
            ['semi_expendable', 'Desk', 'SE-2026-0001'],
            ['ppe', 'Chair', 'PPE-2026-0002'],
        ], $sorted);
    }

    protected function fakeCategory(string $name): ItemCategory
    {
        $category = new ItemCategory([
            'name' => $name,
        ]);
        $category->syncOriginal();

        return $category;
    }

    protected function fakeLine(ItemCategory $category, string $itemName, string $itemCode): RequisitionItem
    {
        $item = new class extends Item
        {
            public ?string $forcedCatalogIdentifier = null;

            public function catalogAssetIdentifier(): ?string
            {
                return $this->forcedCatalogIdentifier;
            }
        };
        $item->forceFill([
            'name' => $itemName,
            'item_code' => $itemCode,
        ]);
        $item->forcedCatalogIdentifier = $itemCode;
        $item->setRelation('category', $category);
        $item->syncOriginal();

        $line = new RequisitionItem([
            'item_id' => 1,
            'quantity' => 1,
        ]);
        $line->setRelation('item', $item);
        $line->setRelation('requisition', null);

        return $line;
    }
}
