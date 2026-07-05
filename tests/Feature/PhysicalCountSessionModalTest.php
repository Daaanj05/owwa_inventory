<?php

namespace Tests\Feature;

use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionForm;
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

    public function test_create_modal_hides_report_form_when_category_scoped(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->mountAction('create')
            ->assertSet('mountedActions.0.data.count_type', PhysicalCountSession::TYPE_RPCSP)
            ->assertSet('mountedActions.0.data.item_category_id', $category->id)
            ->assertFormFieldIsHidden('item_category_id')
            ->assertFormFieldIsHidden('inventory_type_label')
            ->assertFormFieldDoesNotExist('derived_inventory_type_label')
            ->assertFormFieldDoesNotExist('derived_property_class')
            ->assertFormFieldDoesNotExist('report_form_hint');
    }

    public function test_create_modal_prefills_rpcsp_for_semi_expendable_category(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->mountAction('create')
            ->assertSet('mountedActions.0.name', 'create')
            ->assertSet('mountedActions.0.data.count_type', PhysicalCountSession::TYPE_RPCSP)
            ->assertSet('mountedActions.0.data.item_category_id', $category->id);
    }

    public function test_resolve_count_type_for_semi_expendable_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $this->assertSame(
            PhysicalCountSession::TYPE_RPCSP,
            PhysicalCountSessionForm::resolveCountTypeForCategoryId($category->id),
        );
    }

    public function test_list_shows_only_sessions_for_active_category(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $consumableSession = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $consumables->id,
            'count_date' => now(),
            'inventory_type_label' => 'Office Supplies',
            'reference_code' => 'PC-CON-0001',
        ]);

        $semiSession = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $semi->id,
            'count_date' => now(),
            'reference_code' => 'PC-SEMI-0001',
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $consumables->id);

        Livewire::withQueryParams(['category' => (string) $consumables->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertCanSeeTableRecords([$consumableSession])
            ->assertCanNotSeeTableRecords([$semiSession]);

        session()->put('active_item_category_id', $semi->id);

        Livewire::withQueryParams(['category' => (string) $semi->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertCanSeeTableRecords([$semiSession])
            ->assertCanNotSeeTableRecords([$consumableSession]);
    }

    public function test_active_tab_hides_archived_sessions_and_archived_tab_shows_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $activeSession = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
            'reference_code' => 'PC-ACTIVE-0001',
        ]);

        $archivedSession = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
            'reference_code' => 'PC-ARCHIVED-0001',
            'archived_at' => now(),
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertCanSeeTableRecords([$activeSession])
            ->assertCanNotSeeTableRecords([$archivedSession])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$archivedSession])
            ->assertCanNotSeeTableRecords([$activeSession]);
    }

    public function test_archive_table_action_sets_archived_at(): void
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
            'reference_code' => 'PC-ARCHIVE-0001',
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->callTableAction('archive', $session);

        $session->refresh();

        $this->assertNotNull($session->archived_at);
        $this->assertTrue($session->isArchived());
    }

    public function test_restore_table_action_clears_archived_at(): void
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
            'reference_code' => 'PC-RESTORE-0001',
            'archived_at' => now(),
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->set('activeTab', 'archived')
            ->callTableAction('restore', $session);

        $session->refresh();

        $this->assertNull($session->archived_at);
        $this->assertFalse($session->isArchived());
    }

    public function test_archived_session_hides_workflow_footer_actions_in_view_modal(): void
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
            'reference_code' => 'PC-READONLY-0001',
            'archived_at' => now(),
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->set('activeTab', 'archived')
            ->mountTableAction('view', $session)
            ->assertActionHidden(TestAction::make('scanWithPhone'))
            ->assertActionHidden(TestAction::make('preloadExpectedAssets'))
            ->assertActionHidden(TestAction::make('markComplete'))
            ->assertActionHidden(TestAction::make('edit'))
            ->assertActionVisible(TestAction::make('printQrLabels'));
    }

    public function test_scan_route_returns_not_found_for_archived_session(): void
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
            'archived_at' => now(),
        ]);

        $this->actingAs($custodian)
            ->get(PhysicalCountSessionResource::getUrl('scan', ['record' => $session]))
            ->assertNotFound();
    }
}
