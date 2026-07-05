<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockPositionRestockFlag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StockLevelsRestockFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_restock_filter_defaults_to_active_only(): void
    {
        [$category, $item, $office, $custodian] = $this->seedStockPositions();

        StockPositionRestockFlag::markInactive($item->id, $office->id, 100.00, $custodian->id);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->assertSet('restockFilter', 'active')
            ->assertSee('Active only')
            ->assertSee('Inactive only')
            ->tap(function ($component): void {
                $rows = $component->instance()->getStockLevelsFull();
                $this->assertCount(1, $rows);
                $this->assertFalse((bool) ($rows->first()->is_inactive_for_restock ?? false));
            });
    }

    public function test_restock_filter_inactive_only_shows_legacy_positions(): void
    {
        [$category, $item, $office, $custodian] = $this->seedStockPositions();

        StockPositionRestockFlag::markInactive($item->id, $office->id, 100.00, $custodian->id);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('setRestockFilter', 'inactive')
            ->assertSet('restockFilter', 'inactive')
            ->tap(function ($component): void {
                $rows = $component->instance()->getStockLevelsFull();
                $this->assertCount(1, $rows);
                $this->assertTrue((bool) ($rows->first()->is_inactive_for_restock ?? false));
                $this->assertSame(100.0, (float) $rows->first()->unit_cost);
            });
    }

    public function test_toggle_restock_active_moves_row_between_filters(): void
    {
        [$category, $item, $office, $custodian] = $this->seedStockPositions();

        StockPositionRestockFlag::markInactive($item->id, $office->id, 100.00, $custodian->id);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('setRestockFilter', 'inactive')
            ->call('toggleRestockActive', $item->id, $office->id, 100.00)
            ->assertNotified()
            ->tap(function ($component): void {
                $this->assertCount(0, $component->instance()->getStockLevelsFull());
            })
            ->call('setRestockFilter', 'active')
            ->tap(function ($component): void {
                $rows = $component->instance()->getStockLevelsFull();
                $this->assertCount(2, $rows);
                $this->assertFalse($rows->contains(
                    fn (object $row): bool => (float) $row->unit_cost === 100.0 && ($row->is_inactive_for_restock ?? false),
                ));
            });
    }

    /**
     * @return array{0: ItemCategory, 1: Item, 2: Office, 3: User}
     */
    protected function seedStockPositions(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            [
                'reference_code' => 'ACQ-FILTER-100',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 10,
                'unit_cost' => 100.00,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'reference_code' => 'ACQ-FILTER-150',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 5,
                'unit_cost' => 150.00,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        return [$category, $item, $office, $custodian];
    }
}
