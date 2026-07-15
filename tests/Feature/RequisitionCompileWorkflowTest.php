<?php

namespace Tests\Feature;

use App\Filament\Pages\RegionalSupplyCatalog;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Models\Department;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\OwwaTemplateExportService;
use App\Services\RequisitionCompileService;
use App\Support\OwwaCellMapping;
use App\Support\RequisitionLineDisplay;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RequisitionCompileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_employee_requisitions_are_not_eligible_for_compile(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $eligible = app(RequisitionCompileService::class)->filterEligible(collect([$requisition]));

        $this->assertCount(0, $eligible);
    }

    public function test_approved_uncompiled_employee_requisitions_are_eligible(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $eligible = app(RequisitionCompileService::class)->filterEligible(collect([$requisition]));

        $this->assertCount(1, $eligible);
    }

    public function test_compile_service_merges_quantities_with_employee_allocation_summary(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Maria Santos',
        ]);
        $item = Item::factory()->create(['name' => 'Bond Paper']);

        $first = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-A',
            'purpose' => 'Training supplies',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $first->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $second = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-B',
            'purpose' => 'Office use',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $second->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $merged = app(RequisitionCompileService::class)->mergedLineItems(collect([$first, $second]));

        $this->assertCount(1, $merged);
        $this->assertSame(7, $merged[0]['quantity']);
        $this->assertStringContainsString('Maria Santos (REQ-A): 2 endorsed', $merged[0]['line_source_summary']);
        $this->assertStringContainsString('Maria Santos (REQ-B): 5 endorsed', $merged[0]['line_source_summary']);
    }

    public function test_eligible_employee_requisition_options_lists_approved_uncompiled_requests(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $item = Item::factory()->create();

        $eligible = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-ELIGIBLE',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $eligible->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($uc);

        $options = app(RequisitionCompileService::class)->eligibleEmployeeRequisitionOptions($uc);

        $this->assertArrayHasKey($eligible->id, $options);
        $this->assertCount(1, $options);
    }

    public function test_employee_picker_filters_by_selected_office_and_department(): void
    {
        $officeA = Office::factory()->create(['name' => 'Office A']);
        $officeB = Office::factory()->create(['name' => 'Office B']);
        $deptA = Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'A1']);
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'B1']);

        $employeeA = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
        ]);
        $employeeB = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => $deptA->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);
        $uc->refresh();

        $item = Item::factory()->create();

        $reqA = Requisition::query()->create([
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
            'requested_by' => $employeeA->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-A-OFFICE',
        ]);
        RequisitionItem::query()->create(['requisition_id' => $reqA->id, 'item_id' => $item->id, 'quantity' => 1]);

        $reqB = Requisition::query()->create([
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
            'requested_by' => $employeeB->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-B-OFFICE',
        ]);
        RequisitionItem::query()->create(['requisition_id' => $reqB->id, 'item_id' => $item->id, 'quantity' => 1]);

        $service = app(RequisitionCompileService::class);

        $optionsA = $service->eligibleEmployeeRequisitionOptions($uc, $officeA->id, $deptA->id);
        $optionsB = $service->eligibleEmployeeRequisitionOptions($uc, $officeB->id, $deptB->id);

        $this->assertArrayHasKey($reqA->id, $optionsA);
        $this->assertArrayNotHasKey($reqB->id, $optionsA);
        $this->assertArrayHasKey($reqB->id, $optionsB);
        $this->assertArrayNotHasKey($reqA->id, $optionsB);
    }

    public function test_endorsed_quantity_less_than_requested_requires_employee_remarks(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RequisitionCompileService::class)->validateEndorsementLines([
            [
                'requested_quantity' => 10,
                'endorsed_quantity' => 8,
                'employee_remarks' => null,
            ],
        ]);
    }

    public function test_source_endorsements_persist_with_employee_attribution(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'Employee purpose',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $endorsementLines = [
            [
                'source_requisition_id' => $employeeRequisition->id,
                'requisition_item_id' => $line->id,
                'item_id' => $item->id,
                'requested_quantity' => 10,
                'endorsed_quantity' => 8,
                'employee_remarks' => 'Budget limit',
            ],
        ];

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 8]],
            'Office supplies for Q2',
            $office->id,
            $department->id,
            $endorsementLines,
        );

        $this->assertSame(Requisition::STATUS_PENDING, $consolidated->status);
        $this->assertSame('Office supplies for Q2', $consolidated->purpose);
        $this->assertSame(8, $consolidated->items()->first()?->quantity);
        $this->assertSame($consolidated->id, $employeeRequisition->fresh()->compiled_into_requisition_id);

        $endorsement = RequisitionSourceEndorsement::query()->first();
        $this->assertNotNull($endorsement);
        $this->assertSame($employee->id, $endorsement->requested_by_user_id);
        $this->assertSame(10, $endorsement->requested_quantity);
        $this->assertSame(8, $endorsement->endorsed_quantity);
        $this->assertSame('Budget limit', $endorsement->employee_remarks);
    }

    public function test_multi_office_uc_compile_succeeds_with_non_primary_office(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'B1']);
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $officeA->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'A1'])->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 5]],
            'Cross-office compile',
            $officeB->id,
            $deptB->id,
        );

        $this->assertSame($officeB->id, $consolidated->office_id);
        $this->assertSame($consolidated->id, $employeeRequisition->fresh()->compiled_into_requisition_id);
    }

    public function test_unit_consolidator_can_compile_via_new_requisition_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'Employee supplies',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->callAction(
                TestAction::make('create')->schemaComponent(true, 'content'),
                data: [
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'purpose' => 'Compiled office supplies',
                    'source_requisition_ids' => [$employeeRequisition->id],
                    'endorsement_lines' => [
                        [
                            'source_requisition_id' => $employeeRequisition->id,
                            'requisition_item_id' => $line->id,
                            'item_id' => $item->id,
                            'item_category_id' => $item->item_category_id,
                            'requested_quantity' => 10,
                            'endorsed_quantity' => 6,
                            'employee_remarks' => 'Adjusted for budget',
                            'employee_name' => $employee->name,
                            'transaction_number' => $employeeRequisition->transaction_number,
                            'purpose' => 'Employee supplies',
                            'item_name' => $item->name,
                        ],
                    ],
                    'items' => [
                        [
                            'item_category_id' => $item->item_category_id,
                            'item_id' => $item->id,
                            'quantity' => 6,
                            'requested_total' => 10,
                            'allocation_summary' => "{$employee->name}: 6 endorsed",
                        ],
                    ],
                ])
            ->assertNotified();

        $consolidated = Requisition::query()
            ->where('requested_by', $uc->id)
            ->where('status', Requisition::STATUS_PENDING)
            ->latest('id')
            ->first();

        $this->assertNotNull($consolidated);
        $this->assertSame('Compiled office supplies', $consolidated->purpose);
        $this->assertSame(6, $consolidated->items()->first()?->quantity);
        $this->assertSame($consolidated->id, $employeeRequisition->fresh()->compiled_into_requisition_id);
        $this->assertSame(6, RequisitionSourceEndorsement::query()->value('endorsed_quantity'));
    }

    public function test_notification_sent_when_endorsed_quantity_is_reduced(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-2026-0101',
            'purpose' => 'Supplies',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->callAction(
                TestAction::make('create')->schemaComponent(true, 'content'),
                data: [
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'purpose' => 'Batch purpose',
                    'source_requisition_ids' => [$employeeRequisition->id],
                    'endorsement_lines' => [
                        [
                            'source_requisition_id' => $employeeRequisition->id,
                            'requisition_item_id' => $line->id,
                            'item_id' => $item->id,
                            'requested_quantity' => 10,
                            'endorsed_quantity' => 8,
                            'employee_remarks' => 'Over budget',
                            'item_name' => $item->name,
                        ],
                    ],
                    'items' => [
                        [
                            'item_category_id' => $item->item_category_id,
                            'item_id' => $item->id,
                            'quantity' => 8,
                        ],
                    ],
                ],
            )
            ->assertNotified();

        Notification::assertSentTo(
            $employee,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => str_contains($notification->body, 'endorsed 8')
                && str_contains($notification->body, 'Over budget'),
        );
    }

    public function test_unit_consolidator_create_form_includes_compile_picker_and_purpose_without_header_remarks(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertFormFieldExists('source_requisition_ids')
            ->assertFormFieldExists('purpose')
            ->assertFormFieldDoesNotExist('remarks');
    }

    public function test_employee_create_form_includes_purpose_not_line_remarks(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertFormFieldDoesNotExist('source_requisition_ids')
            ->assertFormFieldExists('purpose')
            ->assertFormFieldDoesNotExist('remarks')
            ->assertFormFieldExists('items');
    }

    public function test_catalog_request_url_opens_list_with_create_params(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $url = Livewire::test(RegionalSupplyCatalog::class)
            ->instance()
            ->requestItemUrl($item->id);

        $this->assertStringContainsString('create=1', $url);
        $this->assertStringContainsString('item_id='.$item->id, $url);
    }

    public function test_catalog_deep_link_prefills_create_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $component = Livewire::test(ListRequisitions::class, [
            'create' => 1,
            'item_id' => $item->id,
        ])
            ->assertActionMounted(TestAction::make('create')->schemaComponent(true, 'content'));

        $items = array_values($component->get('mountedActions')[0]['data']['items'] ?? []);

        $this->assertNotEmpty($items);
        $this->assertSame((string) $item->id, (string) ($items[0]['item_id'] ?? ''));
        $this->assertSame((string) $item->item_category_id, (string) ($items[0]['item_category_id'] ?? ''));
        $this->assertSame(1.0, (float) ($items[0]['quantity'] ?? 0));
    }

    public function test_employee_create_action_label_is_new_requisition(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Pages/ListRequisitions.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'New Requisition'", $source);
        $this->assertStringContainsString('->createAnother(false)', $source);
    }

    public function test_employee_create_form_uses_table_repeater_without_line_remarks(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Schemas/RequisitionForm.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->table([', $source);
        $this->assertStringContainsString('owwa-requisition-items-repeater', $source);

        preg_match('/private static function tableRequestItemFields\(bool \$isUnitConsolidator\): array\s*\{(.*?)\n    \}/s', $source, $matches);
        $tableFieldsBody = $matches[1] ?? '';

        $this->assertStringNotContainsString('requestItemRemarksInput', $tableFieldsBody);
        $this->assertStringNotContainsString('stock_available', $tableFieldsBody);
        $this->assertStringNotContainsString('quantity_issued', $tableFieldsBody);
        $this->assertStringNotContainsString('issue_remarks', $tableFieldsBody);
        $this->assertStringContainsString('owwa-requisition-transaction-no', $source);
        $this->assertStringContainsString('owwa-requisition-details-section--compact', $source);
        $this->assertStringContainsString("->label('Purpose')", $source);
        $this->assertStringContainsString('repeaterDeleteVisibleExceptFirst', $source);
        $this->assertStringContainsString('owwa-requisition-purpose-field', $source);
        $this->assertStringContainsString('->markAsRequired()', $source);
    }

    public function test_uc_received_list_filters_by_selected_office_and_department(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $officeA = Office::factory()->create(['name' => 'Office Alpha']);
        $officeB = Office::factory()->create(['name' => 'Office Beta']);
        $deptA = Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'DA']);
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'DB']);

        $employeeA = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
        ]);
        $employeeB = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => $deptA->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);

        $reqA = Requisition::query()->create([
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
            'requested_by' => $employeeA->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => 'REQ-SCOPE-A',
        ]);
        $reqB = Requisition::query()->create([
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
            'requested_by' => $employeeB->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => 'REQ-SCOPE-B',
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $officeA->id,
            'ucDepartmentId' => $deptA->id,
        ])
            ->assertCanSeeTableRecords([$reqA])
            ->assertCanNotSeeTableRecords([$reqB]);
    }

    public function test_uc_received_list_excludes_endorsed_employee_requisitions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-PENDING',
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => 'RIS-SENT-1',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $endorsed = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-ENDORSED',
            'compiled_into_requisition_id' => $consolidated->id,
        ]);

        Livewire::actingAs($uc)
            ->test(ListRequisitions::class, [
                'ucTab' => 'received',
                'ucOfficeId' => $office->id,
                'ucDepartmentId' => $department->id,
            ])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$endorsed]);

        Livewire::actingAs($uc)
            ->test(ListRequisitions::class, [
                'ucTab' => 'sent',
            ])
            ->assertCanSeeTableRecords([$consolidated])
            ->assertCanNotSeeTableRecords([$endorsed, $pending]);
    }

    public function test_uc_create_modal_prefills_office_and_department_from_list_scope(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $office->id,
            'ucDepartmentId' => $department->id,
        ])
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertSet('mountedActions.0.data.office_id', $office->id)
            ->assertSet('mountedActions.0.data.department_id', $department->id);
    }

    public function test_uc_bulk_compile_opens_create_modal_with_sources_preselected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $item = Item::factory()->create();

        $acceptedA = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-BULK-A',
        ]);
        $acceptedA->items()->create([
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $acceptedB = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-BULK-B',
        ]);
        $acceptedB->items()->create([
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $this->actingAs($uc);

        $tableSource = file_get_contents(app_path('Filament/Resources/Requisitions/Tables/RequisitionsTable.php'));
        $this->assertIsString($tableSource);
        $this->assertStringContainsString("BulkAction::make('compile')", $tableSource);

        Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $office->id,
            'ucDepartmentId' => $department->id,
        ])
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'), [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'prefillSourceRequisitionIds' => [$acceptedA->id, $acceptedB->id],
            ])
            ->assertSet('mountedActions.0.data.office_id', $office->id)
            ->assertSet('mountedActions.0.data.department_id', $department->id)
            ->assertSet('mountedActions.0.data.source_requisition_ids', [
                $acceptedA->id,
                $acceptedB->id,
            ])
            ->assertSet('mountedActions.0.data.endorsement_lines', fn ($lines): bool => is_array($lines) && count($lines) === 2);

        Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $office->id,
            'ucDepartmentId' => $department->id,
        ])
            ->call('replaceMountedAction', 'create', [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'prefillSourceRequisitionIds' => [$acceptedA->id],
            ], ['schemaComponent' => 'content'])
            ->assertSet('mountedActions.0.data.source_requisition_ids', [$acceptedA->id]);
    }

    public function test_employee_save_draft_allows_missing_purpose(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $test = Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'));

        $items = $test->get('mountedActions')[0]['data']['items'] ?? [];
        $key = array_key_first($items);

        $test->fillForm([
            'items' => [
                $key => [
                    'item_category_id' => $item->item_category_id,
                    'item_id' => $item->id,
                    'quantity' => 2,
                ],
            ],
        ])
            ->callMountedAction();

        $this->assertDatabaseHas(Requisition::class, [
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
        ]);
    }

    public function test_unit_consolidator_create_requires_purpose_not_line_remarks(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $item = Item::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->callAction(
                TestAction::make('create')->schemaComponent(true, 'content'),
                data: [
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'items' => [
                        [
                            'item_category_id' => $item->item_category_id,
                            'item_id' => $item->id,
                            'quantity' => 1,
                        ],
                    ],
                ],
            )
            ->assertHasFormErrors(['purpose']);

        $this->assertDatabaseCount(Requisition::class, 0);
    }

    public function test_employee_create_modal_shows_regional_stock_for_item_at_supply_office(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Office::factory()->create([
            'name' => 'AAA Empty Regional',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);
        $supplyOffice = Office::factory()->create([
            'name' => 'ZZZ Supply Office',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $supplyOffice->id,
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $supplyOffice->id,
        ]);
        $item = Item::factory()->create();

        \App\Models\Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $supplyOffice->id,
            'quantity' => 15,
            'unit_cost' => 10,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->actingAs($employee);

        $supplyOfficeId = app(\App\Support\SupplyOfficeResolver::class)->resolve();
        $stock = app(\App\Services\InventoryStockService::class)->getStock($item->id, (int) $supplyOfficeId);

        $this->assertSame($supplyOffice->id, $supplyOfficeId);
        $this->assertSame(15, $stock);
        $this->assertStringContainsString(
            'ZZZ Supply Office',
            RequisitionForm::createModalDescription(),
        );
        $this->assertStringContainsString(
            'Save a draft anytime',
            RequisitionForm::createModalDescription(),
        );
        $this->assertStringContainsString(
            'submit even when Available is 0',
            RequisitionForm::createModalDescription(),
        );
    }

    public function test_employee_create_assigns_transaction_number_not_ris_reference(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $this->actingAs($employee);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $requisition->refresh();

        $this->assertNotNull($requisition->transaction_number);
        $this->assertNull($requisition->reference_code);
    }

    public function test_uc_consolidated_requisition_assigns_ris_reference_not_transaction_number(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'UC office supplies',
        ]);

        $requisition->refresh();

        $this->assertNotNull($requisition->reference_code);
        $this->assertNull($requisition->transaction_number);
    }

    public function test_employee_displays_parent_ris_after_compile(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'General',
            'code' => 'GEN',
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $item = Item::factory()->create();

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-0101',
            'purpose' => 'Employee purpose text',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 2]],
            'UC consolidated purpose',
            $office->id,
            $department->id,
            app(RequisitionCompileService::class)->buildEndorsementLines(collect([$employeeRequisition->fresh(['items.item', 'requestedBy'])])),
        );

        $employeeRequisition->refresh();

        $this->assertSame('2026-01-0101', $employeeRequisition->displayTransactionNumber());
        $this->assertSame('Employee purpose text', $employeeRequisition->purpose);
        $this->assertNotNull($employeeRequisition->endorsed_at);
        $this->assertSame(2, $employeeRequisition->employeeLineEndorsement($line->id)?->endorsed_quantity);
    }

    public function test_ris_export_uses_uc_purpose_not_employee_line_remarks(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($uc);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'UC RIS purpose',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'remarks' => 'Employee line remark should not export',
        ]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForRequisition($requisition->fresh(['items.item']));
        $purposeCell = OwwaCellMapping::form('RIS')['header']['purpose']['cell'] ?? 'A32';
        $issueRemarksCol = OwwaCellMapping::columnCell(OwwaCellMapping::form('RIS')['detail']['columns']['issue_remarks'] ?? 'H', OwwaCellMapping::detailRowBase('RIS'));

        $this->assertStringContainsString('UC RIS purpose', (string) ($values[$purposeCell] ?? ''));
        $this->assertSame('', (string) ($values[$issueRemarksCol] ?? ''));
    }

    public function test_ris_export_issue_remarks_column_populated_only_after_sc_issuance(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'Issued supplies',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'remarks' => 'Request remark must not export',
            'issue_remarks' => 'Issued from stockroom A',
            'quantity_issued' => 2,
        ]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForRequisition($requisition->fresh(['items.item']));
        $issueRemarksCol = OwwaCellMapping::columnCell(
            OwwaCellMapping::form('RIS')['detail']['columns']['issue_remarks'] ?? 'H',
            OwwaCellMapping::detailRowBase('RIS'),
        );

        $this->assertSame('Issued from stockroom A', (string) ($values[$issueRemarksCol] ?? ''));
    }

    public function test_identifier_resolves_from_parent_requisition_issuances(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create(['item_code' => 'STK-9001']);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-0102',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'General',
            'code' => 'GEN',
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 1]],
            'Purpose',
            $office->id,
            $department->id,
            app(RequisitionCompileService::class)->buildEndorsementLines(collect([$employeeRequisition->fresh(['items.item', 'requestedBy'])])),
        );

        \App\Models\Issuance::query()->create([
            'reference_code' => '2026-01-0500',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'requisition_id' => $consolidated->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
        ]);

        $employeeRequisition->refresh();
        $line->refresh();

        $this->assertSame('STK-9001', RequisitionLineDisplay::identifierValue($line));
    }
}
