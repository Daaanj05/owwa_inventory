<?php

namespace Tests\Feature;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemsTableValueCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_category_column_visible_only_for_semi_expendable(): void
    {
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $consumables->id]);

        $this->assertFalse(ItemsTable::isActiveSemiExpendableCategory());

        $ppe = ItemCategory::factory()->create(['name' => 'Property, Plant and Equipment']);
        session(['active_item_category_id' => $ppe->id]);

        $this->assertFalse(ItemsTable::isActiveSemiExpendableCategory());

        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        session(['active_item_category_id' => $semi->id]);

        $this->assertTrue(ItemsTable::isActiveSemiExpendableCategory());
    }

    public function test_stale_active_category_resets_to_first_active_category(): void
    {
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        session(['active_item_category_id' => 99999]);

        $resolved = (new class
        {
            use SyncsActiveItemCategory;

            public function resolve(int $categoryId): int
            {
                return self::resolveActiveItemCategoryId($categoryId);
            }
        })->resolve(99999);

        $this->assertContains($resolved, [$consumables->id, $semi->id]);
        $this->assertTrue(
            ItemCategory::query()->whereKey($resolved)->whereNull('archived_at')->exists()
        );
    }

    public function test_archived_active_category_falls_back_to_active_category(): void
    {
        $archived = ItemCategory::factory()->create([
            'name' => 'Legacy PPE',
            'archived_at' => now(),
        ]);
        $active = ItemCategory::factory()->create(['name' => 'Consumables']);

        session(['active_item_category_id' => $archived->id]);

        $resolved = (new class
        {
            use SyncsActiveItemCategory;

            public function resolve(int $categoryId): int
            {
                return self::resolveActiveItemCategoryId($categoryId);
            }
        })->resolve($archived->id);

        $this->assertSame($active->id, $resolved);
    }
}
