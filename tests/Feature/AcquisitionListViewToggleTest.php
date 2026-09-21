<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ListReceivedAcquisitions;
use App\Models\AcquisitionPaperwork;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionListViewToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_pr_list_icon_toggle_filters_active_and_archived(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $active = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Active supplies PR',
            'pr_date' => now(),
            'pr_number' => 'PR-ACTIVE-1',
        ]);

        $archived = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Archived supplies PR',
            'pr_date' => now(),
            'pr_number' => 'PR-ARCHIVED-1',
            'archived_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->withQueryParams(['category' => (string) $category->id])
            ->test(ListAcquisitions::class)
            ->assertSet('showingArchived', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->set('showingArchived', true)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_received_list_shows_opening_toggle_labels(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->withQueryParams(['category' => (string) $category->id])
            ->test(ListReceivedAcquisitions::class)
            ->assertSet('showingOpeningBalances', false)
            ->assertSeeHtml('aria-label="PO/IAR received"')
            ->assertSeeHtml('aria-label="Opening balances"')
            ->assertSeeHtml('Export Report')
            ->assertDontSeeHtml('Record opening balance')
            ->set('showingOpeningBalances', true)
            ->assertSee('Opening balances')
            ->assertSeeHtml('Record opening balance')
            ->assertSeeHtml('Export Report')
            ->set('showingOpeningBalances', false)
            ->assertSeeHtml('Export Report')
            ->assertDontSeeHtml('Record opening balance');
    }
}
