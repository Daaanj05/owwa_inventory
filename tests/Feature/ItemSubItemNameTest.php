<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemSubItemNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_merges_base_name_and_sub_item_into_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        Livewire::actingAs($custodian)
            ->test(CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Bond Paper',
                'sub_item' => 'A4',
                'unit' => 'ream',
                'reorder_level' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
    }

    public function test_create_without_sub_item_uses_base_name_as_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        Livewire::actingAs($custodian)
            ->test(CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Correction Tape',
                'sub_item' => null,
                'unit' => 'piece',
                'reorder_level' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Correction Tape',
            'sub_item' => null,
            'name' => 'Correction Tape',
        ]);
    }

    public function test_create_can_reuse_existing_base_item_for_new_sub_item(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

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
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Bond Paper',
                'sub_item' => 'Long',
                'unit' => 'ream',
                'reorder_level' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
        ]);
    }
}
