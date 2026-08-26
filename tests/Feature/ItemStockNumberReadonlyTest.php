<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\EditItem;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemStockNumberReadonlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_stock_number_is_disabled_on_edit_and_cannot_be_changed(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Alcohol',
            'sub_item' => '500ml',
            'name' => 'Alcohol 500ml',
            'item_code' => 'CON-2026-0099',
            'unit' => 'bottle',
            'reorder_level' => 5,
            'inventory_type' => 'office_supplies',
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
            ->assertFormFieldIsDisabled('item_code')
            ->assertDontSee('Edit stock number manually')
            ->assertDontSeeHtml('>Category</label>')
            ->set('data.item_code', 'HACKED-STOCK-NO')
            ->set('data.item_category_id', $category->id + 999)
            ->fillForm([
                'base_name' => 'Alcohol',
                'sub_item' => '500ml updated',
                'unit' => 'bottle',
                'reorder_level' => 5,
                'inventory_type' => 'office_supplies',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $item->refresh();

        $this->assertSame('CON-2026-0099', $item->item_code);
        $this->assertSame($category->id, $item->item_category_id);
        $this->assertSame('Alcohol 500ml updated', $item->name);
    }
}
