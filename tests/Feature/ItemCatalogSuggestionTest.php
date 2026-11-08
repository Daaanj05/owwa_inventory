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

class ItemCatalogSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_suggestions_list_only_saved_names_in_the_category(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $otherCategory = ItemCategory::factory()->create(['name' => 'Other supplies']);
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
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
            'unit' => 'ream',
        ]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Ballpen',
            'sub_item' => 'Blue',
            'name' => 'Ballpen Blue',
            'unit' => 'piece',
        ]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Archived Family',
            'sub_item' => 'Old',
            'name' => 'Archived Family Old',
            'unit' => 'piece',
            'archived_at' => now(),
        ]);
        Item::factory()->create([
            'item_category_id' => $otherCategory->id,
            'base_name' => 'Other Family',
            'sub_item' => 'Other Variant',
            'name' => 'Other Family Other Variant',
            'unit' => 'piece',
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        $component = Livewire::actingAs($user)
            ->test(CreateItem::class);

        $html = $component->html();
        $lists = $this->suggestionLists($html);
        $flat = collect($lists)->flatten()->all();

        $this->assertContains('Bond Paper', $flat);
        $this->assertContains('Ballpen', $flat);
        $this->assertContains('A4', $flat);
        $this->assertContains('Long', $flat);
        $this->assertContains('Blue', $flat);
        $this->assertNotContains('Archived Family', $flat);
        $this->assertNotContains('Old', $flat);
        $this->assertNotContains('Other Family', $flat);
        $this->assertNotContains('Other Variant', $flat);
        $this->assertStringNotContainsString('<datalist', $component->html());

        $before = Item::query()->count();

        $component
            ->fillForm([
                'item_category_id' => $category->id,
                'base_name' => 'Typed But Not Saved',
                'sub_item' => 'Also Not Saved',
            ]);

        $this->assertSame($before, Item::query()->count());

        $afterTyping = collect($this->suggestionLists($component->html()))->flatten()->all();

        $this->assertNotContains('Typed But Not Saved', $afterTyping);
        $this->assertNotContains('Also Not Saved', $afterTyping);
        $this->assertContains('Bond Paper', $afterTyping);
        $this->assertNotContains('A4', $afterTyping);
        $this->assertNotContains('Blue', $afterTyping);
    }

    public function test_variant_suggestions_follow_the_saved_family(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Ballpen',
            'sub_item' => 'Blue',
            'name' => 'Ballpen Blue',
        ]);

        $this->assertSame(
            ['A4'],
            Item::variantSuggestionsForCategory($category->id, 'bond paper'),
        );
        $this->assertSame(
            [],
            Item::variantSuggestionsForCategory($category->id, 'Typed But Not Saved'),
        );
    }

    /**
     * @return list<list<string>>
     */
    protected function suggestionLists(string $html): array
    {
        preg_match_all('/data-owwa-saved-suggestions="((?:\\\\.|[^"\\\\])*)"/', $html, $matches);

        $lists = [];

        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(stripslashes(html_entity_decode($encoded, ENT_QUOTES)), true);
            $lists[] = is_array($decoded) ? array_values($decoded) : [];
        }

        return $lists;
    }
}
