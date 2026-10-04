<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryStockService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetStartingStockActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_items_list_does_not_expose_set_starting_stock_action(): void
    {
        [$office, $category, $item, $user] = $this->legacyCatalogFixture();

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->assertOk()
            ->assertSee($item->name)
            ->assertActionDoesNotExist(TestAction::make('setOpeningStock')->table($item));
    }

    public function test_can_set_starting_stock_false_when_acquisition_exists_even_if_stock_zero(): void
    {
        [$office, $category, $item, $user] = $this->legacyCatalogFixture();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-ZERO-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 5,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        \Illuminate\Support\Facades\DB::table('issuances')->insert([
            'reference_code' => 'ISS-ZERO-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 5,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(InventoryStockService::class)->forgetMovementTotalsCache();

        $this->assertSame(0, app(InventoryStockService::class)->getStock($item->id, $office->id));
        $this->assertFalse(ItemOpeningStockFields::canSetStartingStock($item, $office->id));
    }

    public function test_stock_levels_does_not_inject_zero_stock_starting_stock_rows(): void
    {
        [$office, $category, $item, $user] = $this->legacyCatalogFixture();

        $this->actingAs($user);

        $component = Livewire::test(StockLevels::class, ['category' => $category->id])
            ->assertOk()
            ->assertDontSee('Set starting stock');

        $rows = $component->instance()->getStockLevelsFull();
        $this->assertFalse(
            $rows->contains(fn (object $row): bool => (int) $row->item_id === $item->id),
        );
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: User}
     */
    protected function legacyCatalogFixture(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Legacy Alcohol 500ml',
            'reorder_level' => 5,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        return [$office, $category, $item, $user];
    }
}
