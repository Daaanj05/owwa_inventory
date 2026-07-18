<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockLevelCrossPageSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selections_persist_across_pages_and_select_all_merges(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $items = Item::factory()->count(12)->create([
            'item_category_id' => $category->id,
        ]);

        foreach ($items as $index => $item) {
            $item->update(['item_code' => 'CON-XP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)]);

            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 2,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('toggleSelectAllOnPage');

        $pageOneSelected = $component->get('selectedKeys');
        $this->assertCount(10, $pageOneSelected);

        $component
            ->call('gotoPage', 2)
            ->assertSet('selectedKeys', $pageOneSelected)
            ->call('toggleSelectAllOnPage');

        $allSelected = $component->get('selectedKeys');
        $this->assertCount(12, $allSelected);
        foreach ($pageOneSelected as $key) {
            $this->assertContains($key, $allSelected);
        }

        $url = $component->instance()->buildStockCardsExportUrl('selected', 'xlsx');
        $this->assertStringContainsString('pairs=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $exportedKeys = explode(',', (string) ($query['pairs'] ?? ''));
        $this->assertEqualsCanonicalizing($allSelected, $exportedKeys);

        $component->call('clearSelection')->assertSet('selectedKeys', []);
    }

    public function test_select_all_checkbox_state_matches_current_page_after_pagination(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $items = Item::factory()->count(12)->create([
            'item_category_id' => $category->id,
        ]);

        foreach ($items as $index => $item) {
            $item->update(['item_code' => 'CON-SEL-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)]);

            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 2,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('toggleSelectAllOnPage')
            ->call('gotoPage', 2);

        $this->assertFalse(
            $component->instance()->isAllOnPageSelected(),
            'Page 2 select-all should be unchecked when only page 1 rows are selected',
        );

        $pageTwoKeys = $component->instance()->getStockLevels()
            ->getCollection()
            ->map(fn (object $row): string => $component->instance()->pairKeyForRow($row))
            ->all();

        foreach ($pageTwoKeys as $key) {
            $this->assertFalse($component->instance()->isRowSelected($key));
        }

        $component->call('toggleSelectAllOnPage');
        $this->assertTrue($component->instance()->isAllOnPageSelected());
        foreach ($pageTwoKeys as $key) {
            $this->assertTrue($component->instance()->isRowSelected($key));
        }
    }
}
