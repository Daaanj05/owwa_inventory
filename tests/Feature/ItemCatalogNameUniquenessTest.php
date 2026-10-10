<?php

namespace Tests\Feature;

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

class ItemCatalogNameUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_rejects_same_family_and_variant_ignoring_case(): void
    {
        [$category, $user] = $this->custodianContext();
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
            ->callAction(TestAction::make('create'), $this->consumablePayload($category->id, 'bond paper', 'a4'))
            ->assertHasActionErrors(['base_name']);

        $this->assertSame(1, Item::query()->where('item_category_id', $category->id)->count());
    }

    public function test_create_allows_same_family_with_a_different_variant(): void
    {
        [$category, $user] = $this->custodianContext();
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
            ->callAction(TestAction::make('create'), $this->consumablePayload($category->id, 'Bond Paper', 'Long'))
            ->assertHasNoActionErrors();

        $this->assertNotNull(Item::query()->where('name', 'Bond Paper Long')->first());
    }

    public function test_create_allows_the_same_catalog_name_in_another_category(): void
    {
        [$category, $user] = $this->custodianContext();
        $other = ItemCategory::query()->firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable'],
        );
        Item::factory()->create([
            'item_category_id' => $other->id,
            'base_name' => 'Folder',
            'sub_item' => null,
            'name' => 'Folder',
            'unit' => 'piece',
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('create'), $this->consumablePayload($category->id, 'Folder', null))
            ->assertHasNoActionErrors();

        $this->assertSame(1, Item::query()->where('item_category_id', $category->id)->where('name', 'Folder')->count());
    }

    public function test_create_allows_a_name_that_exists_only_on_an_archived_item(): void
    {
        [$category, $user] = $this->custodianContext();
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
            'archived_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('create'), $this->consumablePayload($category->id, 'Stapler', null))
            ->assertHasNoActionErrors();

        $this->assertSame(1, Item::query()->active()->where('item_category_id', $category->id)->where('name', 'Stapler')->count());
    }

    public function test_edit_can_keep_its_own_catalog_name_and_cannot_take_another(): void
    {
        [$category, $user] = $this->custodianContext();
        $kept = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
            'reorder_level' => 5,
            'inventory_type' => 'office_supplies',
        ]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
            'unit' => 'ream',
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('edit')->table($kept), [
                'base_name' => 'Bond Paper',
                'sub_item' => 'A4',
                'unit' => 'ream',
                'reorder_level' => 8,
                'inventory_type' => 'office_supplies',
            ])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('edit')->table($kept), [
                'base_name' => 'Bond Paper',
                'sub_item' => 'Long',
                'unit' => 'ream',
                'reorder_level' => 8,
                'inventory_type' => 'office_supplies',
            ])
            ->assertHasActionErrors(['base_name']);

        $this->assertSame('Bond Paper A4', $kept->fresh()->name);
    }

    /**
     * @return array{0: ItemCategory, 1: User}
     */
    protected function custodianContext(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Consumables'],
        );
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        return [$category, $user];
    }

    /**
     * @return array<string, mixed>
     */
    protected function consumablePayload(int $categoryId, string $baseName, ?string $subItem): array
    {
        return [
            'item_category_id' => $categoryId,
            'base_name' => $baseName,
            'sub_item' => $subItem,
            'unit' => 'ream',
            'reorder_level' => 5,
            'inventory_type' => 'office_supplies',
        ];
    }
}
