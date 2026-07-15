<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Actions\EmployeeRequisitionActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeRequisitionDraftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function mountEmployeeCreateWithItem(Item $item, array $itemOverrides = []): \Livewire\Features\SupportTesting\Testable
    {
        $test = Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'));

        $items = $test->get('mountedActions')[0]['data']['items'] ?? [];
        $key = is_array($items) && $items !== [] ? array_key_first($items) : (string) \Illuminate\Support\Str::uuid();

        return $test->fillForm([
            'items' => [
                $key => array_merge([
                    'item_category_id' => $item->item_category_id,
                    'item_id' => $item->id,
                    'quantity' => 1,
                ], $itemOverrides),
            ],
        ]);
    }

    public function test_employee_create_submit_moves_requisition_to_pending_and_notifies_uc(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $test = Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'));

        $items = $test->get('mountedActions')[0]['data']['items'] ?? [];
        $key = is_array($items) && $items !== [] ? array_key_first($items) : (string) \Illuminate\Support\Str::uuid();

        $test->fillForm([
            'purpose' => 'Quarterly office supplies',
            'items' => [
                $key => [
                    'item_category_id' => $item->item_category_id,
                    'item_id' => $item->id,
                    'quantity' => 2,
                ],
            ],
        ])
            ->callMountedAction(['workflow' => EmployeeRequisitionActions::WORKFLOW_SUBMIT]);

        $requisition = Requisition::query()->where('requested_by', $employee->id)->first();

        $this->assertNotNull($requisition);
        $this->assertSame(Requisition::STATUS_PENDING, $requisition->status);
        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_employee_create_draft_does_not_notify_unit_consolidator(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $this->mountEmployeeCreateWithItem($item)
            ->callMountedAction();

        Notification::assertNotSentTo($uc, RequisitionWorkflowDatabaseNotification::class);

        $this->assertDatabaseHas(Requisition::class, [
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
        ]);
    }

    public function test_employee_submit_requires_purpose(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $this->mountEmployeeCreateWithItem($item, ['quantity' => 2])
            ->callMountedAction(['workflow' => EmployeeRequisitionActions::WORKFLOW_SUBMIT])
            ->assertHasErrors();

        $this->assertDatabaseCount(Requisition::class, 0);
    }

    public function test_draft_requisition_shows_draft_status_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
        ]);

        $this->assertSame('Draft', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_archive_and_restore_actions_only_apply_to_drafts(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $draft = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $draft->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListRequisitions::class)
            ->callAction(TestAction::make('archive')->table($draft));

        $draft->refresh();
        $this->assertNotNull($draft->archived_at);

        Livewire::test(ListRequisitions::class)
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$draft])
            ->callAction(TestAction::make('restore')->table($draft));

        $this->assertNull($draft->fresh()->archived_at);

        Livewire::test(ListRequisitions::class)
            ->assertActionHidden(TestAction::make('archive')->table($pending))
            ->assertActionVisible(TestAction::make('edit')->table($pending));
    }

    public function test_requisition_tabs_exclude_all_for_employee_and_uc(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Pages/ListRequisitions.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("Tab::make('All')", $source);
        $this->assertStringContainsString('STATUS_DRAFT', $source);
    }

    public function test_employee_table_hides_requested_by_column_and_bulk_delete(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Tables/RequisitionsTable.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->visible(! $isEmployeeViewer)', $source);
        $this->assertStringContainsString('EmployeeRequisitionActions::tableActionGroup()', $source);
        $this->assertStringContainsString('->visible(fn (): bool => ! $isEmployeeViewer)', $source);
    }
}
