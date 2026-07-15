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

class ItemArchiveActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supply_custodian_can_archive_and_restore_item_from_table_actions(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper Archive Test',
            'item_code' => 'CON-ARCH-001',
            'archived_at' => null,
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->set('activeTab', 'active')
            ->callAction(TestAction::make('archive')->table($item));

        $this->assertNotNull($item->fresh()->archived_at);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->set('activeTab', 'archived')
            ->callAction(TestAction::make('restore')->table($item->fresh()));

        $this->assertNull($item->fresh()->archived_at);
    }
}
