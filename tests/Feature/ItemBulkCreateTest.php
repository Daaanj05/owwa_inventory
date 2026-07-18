<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Support\ItemPropertyClass;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemBulkCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supply_custodian_can_bulk_create_consumable_items(): void
    {
        $office = Office::factory()->create();
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
            ->callAction(TestAction::make('bulkCreateItems')->schemaComponent(true, 'content'), [
                'item_category_id' => $category->id,
                'items' => [
                    [
                        'base_name' => 'Bond Paper',
                        'sub_item' => 'A4',
                        'unit' => 'ream',
                        'reorder_level' => 10,
                        'days_to_consume' => 30,
                    ],
                    [
                        'base_name' => 'Ballpen',
                        'sub_item' => 'Blue',
                        'unit' => 'piece',
                        'reorder_level' => 50,
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
            'reorder_level' => 10,
        ]);

        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'base_name' => 'Ballpen',
            'sub_item' => 'Blue',
            'name' => 'Ballpen Blue',
            'unit' => 'piece',
            'reorder_level' => 50,
        ]);

        $this->assertSame(2, Item::query()->where('item_category_id', $category->id)->count());
    }

    public function test_bulk_create_rejects_duplicate_catalog_names(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('bulkCreateItems')->schemaComponent(true, 'content'), [
                'item_category_id' => $category->id,
                'items' => [
                    [
                        'base_name' => 'Bond Paper',
                        'sub_item' => 'A4',
                        'unit' => 'ream',
                        'reorder_level' => 10,
                    ],
                ],
            ])
            ->assertHasActionErrors();

        $this->assertSame(1, Item::query()->where('item_category_id', $category->id)->count());
    }

    public function test_bulk_create_semi_expendable_items_with_required_fields(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Office equipment',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('bulkCreateItems')->schemaComponent(true, 'content'), [
                'item_category_id' => $category->id,
                'items' => [
                    [
                        'base_name' => 'Office Chair',
                        'sub_item' => null,
                        'unit' => 'piece',
                        'reorder_level' => 0,
                        'property_class' => ItemPropertyClass::OfficeEquipment,
                        'uacs_object_code_id' => $uacs->id,
                        'estimated_useful_life' => '5 yrs',
                        'description' => 'Ergonomic chair',
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'name' => 'Office Chair',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
            'estimated_useful_life' => '5 yrs',
            'description' => 'Ergonomic chair',
        ]);
    }

    public function test_bulk_create_ppe_items_without_property_class_field(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $uacs = UacsObjectCode::query()->create([
            'code' => '106-03',
            'name' => 'Office Equipment',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('bulkCreateItems')->schemaComponent(true, 'content'), [
                'item_category_id' => $category->id,
                'items' => [
                    [
                        'base_name' => 'Desktop Computer',
                        'sub_item' => null,
                        'unit' => 'unit',
                        'reorder_level' => 0,
                        'uacs_object_code_id' => $uacs->id,
                        'description' => 'Brand X desktop',
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $item = Item::query()->where('name', 'Desktop Computer')->first();
        $this->assertNotNull($item);
        $this->assertSame(ItemPropertyClass::OfficeEquipment, $item->property_class);
        $this->assertSame($uacs->id, $item->uacs_object_code_id);
        $this->assertSame('Brand X desktop', $item->description);
        $this->assertNotEmpty($item->ppe_property_number);
    }
}
