<?php

namespace Tests\Feature;

use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhysicalCountSessionModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_route_redirects_to_index_with_view_query(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        $this->actingAs($custodian)
            ->get(PhysicalCountSessionResource::getUrl('view', ['record' => $session]))
            ->assertRedirect(PhysicalCountSessionResource::viewModalUrl($session));
    }

    public function test_list_page_binds_view_action_from_query(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams([
            'tableAction' => 'view',
            'tableActionRecord' => (string) $session->id,
            'category' => (string) $category->id,
        ])
            ->test(ListPhysicalCountSessions::class)
            ->assertSet('defaultTableAction', 'view')
            ->assertSet('defaultTableActionRecord', (string) $session->id);
    }

    public function test_custodian_can_open_view_modal_from_table(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
            'reference_code' => 'PC-TEST-0001',
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertCanSeeTableRecords([$session])
            ->mountTableAction('view', $session)
            ->assertActionMounted(TestAction::make('view')->table($session));
    }
}
