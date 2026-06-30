<?php

namespace Tests\Feature;

use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalInventoryPlans\Pages\ListPhysicalInventoryPlans;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryPlanResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_custodian_can_create_plan_with_lines(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $cutOff = now()->addMonth()->toDateString();
        $planned = now()->addWeek()->toDateString();

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        $livewire = Livewire::test(ListPhysicalInventoryPlans::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'));

        $lineKey = array_key_first($livewire->get('mountedActions')[0]['data']['lines'] ?? []);

        $livewire
            ->fillForm([
                'title' => 'Year-end inventory FY 2026',
                'period_label' => 'FY 2026',
                'cut_off_date' => $cutOff,
                'item_category_id' => $category->id,
                'lines' => [
                    $lineKey => [
                        'office_id' => $office->id,
                        'item_category_id' => $category->id,
                        'planned_date' => $planned,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(PhysicalInventoryPlan::class, [
            'title' => 'Year-end inventory FY 2026',
            'status' => PhysicalInventoryPlan::STATUS_DRAFT,
        ]);

        $plan = PhysicalInventoryPlan::query()->where('title', 'Year-end inventory FY 2026')->first();
        $this->assertNotNull($plan);
        $this->assertDatabaseHas(PhysicalInventoryPlanLine::class, [
            'physical_inventory_plan_id' => $plan->id,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
        ]);
    }

    public function test_start_count_action_links_session_and_redirects(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $plan = PhysicalInventoryPlan::factory()->approved()->create([
            'item_category_id' => $category->id,
        ]);

        $line = PhysicalInventoryPlanLine::factory()->create([
            'physical_inventory_plan_id' => $plan->id,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'planned_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::test(ListPhysicalInventoryPlans::class)
            ->call('startPlanLineCount', $line->id)
            ->assertRedirect(PhysicalCountSessionResource::getUrl('view', [
                'record' => $line->fresh()->physical_count_session_id,
            ]));

        $this->assertNotNull($line->fresh()->physical_count_session_id);
        $this->assertSame(PhysicalInventoryPlan::STATUS_IN_PROGRESS, $plan->fresh()->status);
    }

    public function test_view_route_redirects_to_index_with_view_query(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $plan = PhysicalInventoryPlan::factory()->create([
            'item_category_id' => $category->id,
        ]);

        session()->put('active_item_category_id', $category->id);

        $this->actingAs($custodian)
            ->get(PhysicalInventoryPlanResource::getUrl('view', ['record' => $plan]))
            ->assertRedirect(PhysicalInventoryPlanResource::viewModalUrl($plan));
    }

    public function test_non_custodian_cannot_access_inventory_plans(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)
            ->get(PhysicalInventoryPlanResource::getUrl('index'))
            ->assertNotFound();
    }

    public function test_active_tab_hides_archived_plans_and_archived_tab_shows_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $activePlan = PhysicalInventoryPlan::factory()->create([
            'item_category_id' => $category->id,
            'title' => 'Active schedule',
        ]);

        $archivedPlan = PhysicalInventoryPlan::factory()->create([
            'item_category_id' => $category->id,
            'title' => 'Archived schedule',
        ]);
        $archivedPlan->delete();

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::test(ListPhysicalInventoryPlans::class)
            ->assertCanSeeTableRecords([$activePlan])
            ->assertCanNotSeeTableRecords([$archivedPlan])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$archivedPlan])
            ->assertCanNotSeeTableRecords([$activePlan]);
    }

    public function test_archive_table_action_soft_deletes_plan(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $plan = PhysicalInventoryPlan::factory()->create([
            'item_category_id' => $category->id,
            'title' => 'Schedule to archive',
        ]);

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::test(ListPhysicalInventoryPlans::class)
            ->callTableAction('archive', $plan);

        $this->assertSoftDeleted($plan);
    }

    public function test_restore_table_action_restores_archived_plan(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $plan = PhysicalInventoryPlan::factory()->create([
            'item_category_id' => $category->id,
            'title' => 'Schedule to restore',
        ]);
        $plan->delete();

        $this->actingAs($custodian);
        session()->put('active_item_category_id', $category->id);

        Livewire::test(ListPhysicalInventoryPlans::class)
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$plan])
            ->callTableAction('restore', $plan);

        $this->assertNotSoftDeleted($plan);
    }
}
