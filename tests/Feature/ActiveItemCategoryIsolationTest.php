<?php

namespace Tests\Feature;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveItemCategoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_items_query_uses_livewire_category_not_session(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $bond = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'base_name' => 'Bond Paper',
            'name' => 'Bond Paper',
            'unit' => 'ream',
        ]);
        $chair = Item::factory()->create([
            'item_category_id' => $semi->id,
            'base_name' => 'Office chair',
            'name' => 'Office chair',
            'unit' => 'unit',
            'value_type' => 'low',
            'semi_expendable_property_number' => 'SPLV-2026-FF-001',
        ]);

        // Simulate another browser tab leaving session on Semi-Expendable.
        session(['active_item_category_id' => $semi->id]);

        Livewire::actingAs($custodian)
            ->withQueryParams(['category' => $consumables->id])
            ->test(ListItems::class)
            ->assertCanSeeTableRecords([$bond])
            ->assertCanNotSeeTableRecords([$chair]);
    }

    public function test_url_category_takes_priority_over_session(): void
    {
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        session(['active_item_category_id' => $semi->id]);

        $this->get('/up?category='.$consumables->id);

        $this->assertSame(
            $consumables->id,
            SyncsActiveItemCategory::resolveCategoryIdFromContext(),
        );
    }

    public function test_list_items_create_uses_url_category_not_session(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $ppe = ItemCategory::query()->firstWhere('name', 'Property, Plant and Equipment')
            ?? ItemCategory::factory()->create(['name' => 'Property, Plant and Equipment']);

        session(['active_item_category_id' => $ppe->id]);

        $create = TestAction::make('create')->schemaComponent(true, 'content');

        Livewire::actingAs($custodian)
            ->withQueryParams(['category' => $consumables->id])
            ->test(ListItems::class)
            ->mountAction($create)
            ->fillForm([
                'base_name' => 'Bond paper',
                'unit' => 'ream',
                'reorder_level' => 5,
                'inventory_type' => 'office_supplies',
            ])
            ->mountAction('submit')
            ->callMountedAction()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'name' => 'Bond paper',
            'base_name' => 'Bond paper',
            'item_category_id' => $consumables->id,
        ]);
    }

    public function test_create_item_form_hides_auto_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);

        Livewire::actingAs($custodian)
            ->withQueryParams(['category' => $consumables->id])
            ->test(ListItems::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertFormFieldIsHidden('item_code')
            ->assertFormFieldIsHidden('value_type_display');
    }
}
