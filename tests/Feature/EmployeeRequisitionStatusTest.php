<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Distribution;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionCompileService;
use App\Support\EmployeeRequisitionFulfillment;
use App\Support\EmployeeRequisitionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRequisitionStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Department}
     */
    private function unitConsolidatorForOffice(Office $office): array
    {
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
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

        return [$uc, $department];
    }

    public function test_pending_employee_requisition_shows_pending_uc_review_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => '2026-01-1001',
        ]);

        $this->assertSame('Pending UC review', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_accepted_uncompiled_employee_requisition_shows_reviewed_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1002',
        ]);

        $this->assertSame('Reviewed', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_compiled_employee_requisition_shows_endorsed_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        [$uc, $department] = $this->unitConsolidatorForOffice($office);
        $item = Item::factory()->create();

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1003',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 2]],
            'Office supplies',
            $office->id,
            $department->id,
        );

        $employeeRequisition->refresh();

        $this->assertSame('Endorsed to SC', EmployeeRequisitionStatus::label($employeeRequisition));
        $this->assertNotNull($employeeRequisition->endorsed_at);
        $this->assertSame($uc->id, $employeeRequisition->endorsed_by);
        $this->assertSame($consolidated->id, $employeeRequisition->compiled_into_requisition_id);
    }

    public function test_closed_partial_distribution_shows_partially_distributed_closed_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1004',
            'closed_at' => now(),
            'fulfillment_summary' => 'Issued 2 of 5',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 5,
        ]);

        $this->assertSame('Partially issued — Closed', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_rejected_employee_requisition_shows_rejected_label(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_REJECTED,
            'transaction_number' => '2026-01-1005',
        ]);

        $this->assertSame('Rejected', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_uncompiled_employee_requisition_ignores_stray_quantity_issued_for_fulfillment(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1006',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 5,
            'quantity_issued' => 5,
        ]);

        $this->assertSame('Reviewed', EmployeeRequisitionStatus::label($requisition));
        $this->assertNull(EmployeeRequisitionFulfillment::label($requisition));
    }

    public function test_endorsed_employee_requisition_uses_compiled_ris_fulfillment(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        [$uc, $department] = $this->unitConsolidatorForOffice($office);
        $item = Item::factory()->create();

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1007',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 2]],
            'Office supplies',
            $office->id,
            $department->id,
        );

        $consolidatedLine = $consolidated->items()->first();
        $consolidatedLine?->update(['quantity_issued' => 2]);

        $employeeRequisition->refresh();

        $this->assertSame('Fully issued', EmployeeRequisitionFulfillment::label($employeeRequisition));
    }

    public function test_endorsed_employee_requisition_with_partial_distribution_shows_partially_issued(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        [$uc, $department] = $this->unitConsolidatorForOffice($office);
        $item = Item::factory()->create();

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-1008',
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
            'Office supplies',
            $office->id,
            $department->id,
        );

        $employeeRequisition->refresh();

        Distribution::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $this->assertSame($consolidated->id, $employeeRequisition->compiled_into_requisition_id);
        $this->assertSame('Partially issued', EmployeeRequisitionFulfillment::label($employeeRequisition));
    }

    public function test_requisitions_table_uses_employee_request_status_for_employee_records(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Tables/RequisitionsTable.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('workflowStateLabel', $source);
        $this->assertStringContainsString('EmployeeRequisitionFulfillment::label($record)', $source);
        $this->assertStringNotContainsString("TextColumn::make('fulfillment_sub_state')", $source);
        $this->assertStringNotContainsString("->label('Fulfillment')", $source);
        $this->assertStringContainsString("BulkAction::make('compile')", $source);
    }

    public function test_requisition_form_office_department_placeholders_are_not_selectable(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Schemas/RequisitionForm.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Select::make('office_id')", $source);
        $this->assertStringContainsString("Select::make('department_id')", $source);
        $this->assertStringContainsString('->selectablePlaceholder(false)', $source);
        $this->assertStringNotContainsString("->placeholder('None')", $source);
    }
}
