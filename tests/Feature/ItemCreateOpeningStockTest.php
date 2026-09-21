<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockOpeningBalance;
use App\Models\User;
use App\Support\ItemMeasurementUnitInput;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemCreateOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_item_does_not_set_opening_stock(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('create')->schemaComponent(true, 'content'), [
                'item_category_id' => $category->id,
                'base_name' => 'Bond Paper',
                'sub_item' => 'Legal',
                'unit' => 'ream',
                'reorder_level' => 5,
                'inventory_type' => 'office_supplies',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $item = Item::query()->where('name', 'Bond Paper Legal')->first();
        $this->assertNotNull($item);
        $this->assertDatabaseMissing(StockOpeningBalance::class, [
            'item_id' => $item->id,
        ]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->assertActionVisible(TestAction::make('setOpeningStock')->table($item));
    }

    public function test_create_form_does_not_show_starting_stock_fields(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertDontSee('Starting quantity')
            ->assertDontSee('Starting unit cost')
            ->assertDontSee('Optional. If blank, starting stock is stored at');
    }

    public function test_measurement_unit_pattern_helpers(): void
    {
        $this->assertFalse(ItemMeasurementUnitInput::isValid('ream2'));
        $this->assertTrue(ItemMeasurementUnitInput::isValid('ream'));
        $this->assertTrue(ItemMeasurementUnitInput::isValid('piece'));
    }
}
