<?php

namespace Tests\Feature;

use App\Filament\Resources\ItemAttributeOptions\Pages\ManageItemAttributeOptions;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\ItemAttributeOptionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemAttributeOptionSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_manage_item_attribute_options(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);
        $this->actingAs($admin);

        Livewire::test(ManageItemAttributeOptions::class)
            ->callAction('create', [
                'kind' => ItemAttributeOption::KIND_UNIT,
                'value' => 'carton',
                'label' => 'carton',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ItemAttributeOption::class, [
            'kind' => ItemAttributeOption::KIND_UNIT,
            'value' => 'carton',
            'label' => 'carton',
        ]);
    }

    public function test_item_form_selects_attribute_options_and_base_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seed(ItemAttributeOptionSeeder::class);

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);

        Livewire::actingAs($custodian)
            ->test(CreateItem::class)
            ->assertFormFieldExists('base_name')
            ->assertFormFieldExists('unit')
            ->assertFormFieldExists('inventory_type')
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Bond Paper',
                'sub_item' => 'Long',
                'unit' => 'ream',
                'reorder_level' => 10,
                'inventory_type' => 'office_supplies',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
            'unit' => 'ream',
            'inventory_type' => 'office_supplies',
        ]);
    }
}
