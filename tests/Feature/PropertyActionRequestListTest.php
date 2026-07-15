<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestEmployeeActions;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyActionRequestListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_navigation_label_is_property_return(): void
    {
        $this->assertSame('Property Return', PropertyActionRequestResource::getNavigationLabel());
        $this->assertSame('Property Return', PropertyActionRequestResource::getModelLabel());
        $this->assertSame('Property Returns', PropertyActionRequestResource::getPluralModelLabel());
    }

    public function test_employee_create_draft_without_reason_succeeds(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction()
            ->assertNotified();

        $request = PropertyActionRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(PropertyActionRequest::STATUS_DRAFT, $request->status);
        $this->assertNull($request->reason_code);
    }

    public function test_employee_submit_requires_reason(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction(['workflow' => PropertyActionRequestEmployeeActions::WORKFLOW_SUBMIT])
            ->assertHasErrors();

        $this->assertDatabaseCount(PropertyActionRequest::class, 0);
    }

    public function test_employee_submit_moves_request_to_pending_uc(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction(['workflow' => PropertyActionRequestEmployeeActions::WORKFLOW_SUBMIT])
            ->assertNotified();

        $request = PropertyActionRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_UC, $request->status);
    }

    public function test_employee_archive_and_restore_draft(): void
    {
        [$employee, $issuance] = $this->seedEmployeeIssuance();

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $issuance->office_id,
            'department_id' => $issuance->department_id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->callAction(TestAction::make('archive')->table($request))
            ->assertNotified();

        $this->assertNotNull($request->fresh()->archived_at);

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class, ['activeTab' => 'archived'])
            ->callAction(TestAction::make('restore')->table($request))
            ->assertNotified();

        $this->assertNull($request->fresh()->archived_at);
    }

    public function test_uc_received_list_filters_pending_uc_by_office_and_department(): void
    {
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

        $requestA = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-A',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employeeA->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);
        $requestB = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-B',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employeeB->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);

        Livewire::actingAs($uc)
            ->test(ListPropertyActionRequests::class, [
                'ucTab' => 'received',
                'ucOfficeId' => $officeA->id,
                'ucDepartmentId' => $deptA->id,
            ])
            ->assertCanSeeTableRecords([$requestA])
            ->assertCanNotSeeTableRecords([$requestB]);
    }

    public function test_uc_sent_list_shows_endorsed_employee_property_returns(): void
    {
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

        $pending = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-PENDING',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);

        $endorsed = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-SENT',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
            'uc_approved_by' => $uc->id,
            'uc_approved_at' => now(),
        ]);

        Livewire::actingAs($uc)
            ->test(ListPropertyActionRequests::class, [
                'ucTab' => 'received',
                'ucOfficeId' => $office->id,
                'ucDepartmentId' => $department->id,
            ])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$endorsed]);

        Livewire::actingAs($uc)
            ->test(ListPropertyActionRequests::class, [
                'ucTab' => 'sent',
            ])
            ->assertCanSeeTableRecords([$endorsed])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_uc_send_to_sc_moves_draft_to_pending_sc(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $issuance->office_id,
            'department_id' => $issuance->department_id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $issuance->office_id, 'department_id' => $issuance->department_id],
        ]);

        $this->actingAs($uc);

        Livewire::test(ListPropertyActionRequests::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $issuance->office_id,
            'ucDepartmentId' => $issuance->department_id,
        ])
            ->mountAction('create')
            ->setActionData([
                'office_id' => $issuance->office_id,
                'department_id' => $issuance->department_id,
                'accountable_user_id' => $employee->id,
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction(['workflow' => PropertyActionRequestEmployeeActions::WORKFLOW_SEND_TO_SC])
            ->assertNotified()
            ->assertSet('ucTab', 'sent');

        $request = PropertyActionRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $request->status);
        $this->assertSame($uc->id, $request->uc_approved_by);
        $this->assertSame($employee->id, $request->accountable_user_id);
        $this->assertNotNull($request->uc_approved_at);
    }

    public function test_uc_property_return_form_lists_employee_issuances_ordered_by_eul(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/PropertyActionRequests/Schemas/PropertyActionRequestForm.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("->label('Employee')", $source);
        $this->assertStringContainsString('orderByRaw(\'case when eul_expires_at is null then 1 else 0 end\')', $source);
        $this->assertStringNotContainsString('Offline Approval (SC Gate)', $source);
        $this->assertStringContainsString('->selectablePlaceholder(false)', $source);
    }

    public function test_uc_endorse_switches_to_sent_tab(): void
    {
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

        $pending = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-ENDORSE',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);

        Livewire::actingAs($uc)
            ->test(ListPropertyActionRequests::class, [
                'ucTab' => 'received',
                'ucOfficeId' => $office->id,
                'ucDepartmentId' => $department->id,
            ])
            ->callAction(TestAction::make('ucApprove')->table($pending))
            ->assertNotified()
            ->assertSet('ucTab', 'sent')
            ->assertCanSeeTableRecords([$pending->fresh()]);

        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $pending->fresh()->status);
    }

    public function test_send_to_supply_custodian_workflow_service(): void
    {
        [$employee, $uc, $custodian, $issuance] = $this->seedPropertyContext();

        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAREQ-SC',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $uc->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'department_id' => $issuance->department_id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'sort_order' => 0,
        ]);

        app(PropertyActionRequestWorkflowService::class)->sendToSupplyCustodian($request->fresh(), $uc);

        $request->refresh();
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $request->status);
        $this->assertSame($uc->id, $request->uc_approved_by);
    }

    /**
     * @return array{0: User, 1: Issuance, 2: ItemCategory}
     */
    protected function seedEmployeeIssuance(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

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
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-LIST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 2500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->first();
        $this->assertNotNull($unit);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-LIST-1',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-LIST-001',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 2500,
            'amount' => 2500,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => $unit->property_number,
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        return [$employee, $issuance, $category];
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Issuance}
     */
    protected function seedPropertyContext(): array
    {
        [$employee, $issuance] = $this->seedEmployeeIssuance();

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $issuance->office_id,
            'department_id' => $issuance->department_id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        return [$employee, $uc, $custodian, $issuance];
    }
}
