<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\ItemPropertyClass;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SemiItemEulRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_semi_item_requires_estimated_useful_life_on_create(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        session(['active_item_category_id' => $category->id]);

        Livewire::actingAs($custodian)
            ->test(CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                'name' => 'Office chair',
                'unit' => 'piece',
                'reorder_level' => 0,
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'estimated_useful_life' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['estimated_useful_life' => 'required']);
    }

    public function test_semi_item_create_succeeds_with_valid_eul(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        session(['active_item_category_id' => $category->id]);

        Livewire::actingAs($custodian)
            ->test(CreateItem::class)
            ->fillForm([
                'item_category_id' => $category->id,
                'name' => 'Office chair',
                'unit' => 'piece',
                'reorder_level' => 0,
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'estimated_useful_life' => '5 yrs',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('items', [
            'name' => 'Office chair',
            'estimated_useful_life' => '5 yrs',
        ]);
    }
}
