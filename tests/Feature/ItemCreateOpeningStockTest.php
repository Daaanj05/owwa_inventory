<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockOpeningBalance;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Support\WhitelistedTextInput;
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

    public function test_create_item_with_opening_stock_sets_balance_and_no_row_action(): void
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
                ItemOpeningStockFields::QUANTITY_KEY => 40,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $item = Item::query()->where('name', 'Bond Paper Legal')->first();
        $this->assertNotNull($item);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 40,
        ]);
        $this->assertSame(40, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, null));
        $this->assertSame(40, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 0));

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->assertActionDoesNotExist(TestAction::make('setOpeningStock')->table($item));
    }

    public function test_create_requires_confirmation_before_saving(): void
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

        $create = TestAction::make('create')->schemaComponent(true, 'content');

        $component = Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->mountAction($create)
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Bond Paper',
                'sub_item' => 'Legal',
                'unit' => 'ream',
                'reorder_level' => 5,
                'inventory_type' => 'office_supplies',
                ItemOpeningStockFields::QUANTITY_KEY => 40,
            ])
            ->assertActionMounted($create);

        $this->assertDatabaseMissing(Item::class, ['name' => 'Bond Paper Legal']);

        $component
            ->mountAction('submit')
            ->assertActionMounted([$create, 'submit']);

        $mounted = $component->instance()->getMountedAction();
        $this->assertNotNull($mounted);
        $this->assertSame('Create this item?', $mounted->getModalHeading());
        $this->assertStringContainsString(
            'Do you confirm the details are correct?',
            (string) $mounted->getModalDescription(),
        );

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, ['name' => 'Bond Paper Legal']);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'office_id' => $office->id,
            'quantity' => 40,
        ]);
    }

    public function test_create_form_shows_starting_unit_cost_for_consumables_when_qty_set(): void
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

        Livewire::test(\App\Filament\Resources\Items\Pages\CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                ItemOpeningStockFields::QUANTITY_KEY => 10,
            ])
            ->assertFormFieldIsVisible(ItemOpeningStockFields::UNIT_COST_KEY)
            ->assertSee('Optional. If blank, starting stock is stored at');
    }

    public function test_create_form_renders_alpine_whitelist_guards(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::test(\App\Filament\Resources\Items\Pages\CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
            ])
            ->assertSeeHtml('data-whitelist="'.WhitelistedTextInput::DIGITS_MARKER.'"')
            ->assertSeeHtml('data-whitelist="'.WhitelistedTextInput::DECIMAL_MARKER.'"')
            ->assertSeeHtml('data-whitelist="'.WhitelistedTextInput::LETTERS_MARKER.'"')
            ->assertSeeHtml('@beforeinput')
            ->assertSeeHtml('@keydown')
            ->assertSeeHtml('/^[0-9]$/')
            ->assertSeeHtml('/^[A-Za-z \\-]$/');
    }

    public function test_measurement_unit_digits_are_scrubbed_on_blur_and_invalid_pattern(): void
    {
        $this->assertFalse(\App\Support\ItemMeasurementUnitInput::isValid('ream2'));
        $this->assertTrue(\App\Support\ItemMeasurementUnitInput::isValid('ream'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::test(\App\Filament\Resources\Items\Pages\CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                'unit' => 'ream2',
            ])
            ->assertFormSet([
                'unit' => 'ream',
            ]);
    }
}
