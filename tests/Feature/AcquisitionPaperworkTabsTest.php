<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionPaperworkTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisitions_list_shows_workflow_tabs_and_new_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class)
            ->assertSee('New acquisition')
            ->assertSee('In progress')
            ->assertSee('Received')
            ->assertSee('All');
    }

    public function test_acquisitions_list_shows_status_column_heading(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class)
            ->assertSee('Status');
    }
}
