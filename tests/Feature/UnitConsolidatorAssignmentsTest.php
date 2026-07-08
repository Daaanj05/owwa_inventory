<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\RequisitionNotificationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitConsolidatorAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_consolidator_can_sync_multiple_assignments(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'A']);
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'B']);

        $user = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
        ]);

        $user->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => $deptA->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);

        $user->refresh();

        $this->assertCount(2, $user->assignments);
        $this->assertSame($officeA->id, $user->office_id);
        $this->assertSame($deptA->id, $user->department_id);
        $this->assertTrue($user->coversOfficeDepartment($officeB->id, $deptB->id));
    }

    public function test_requisition_scope_includes_all_assigned_departments(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'A']);
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'B']);

        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $uc->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => $deptA->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);

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

        $reqA = Requisition::query()->create([
            'office_id' => $officeA->id,
            'department_id' => $deptA->id,
            'requested_by' => $employeeA->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $reqB = Requisition::query()->create([
            'office_id' => $officeB->id,
            'department_id' => $deptB->id,
            'requested_by' => $employeeB->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $visibleIds = $uc->applyUnitConsolidatorRequisitionScope(Requisition::query())
            ->pluck('id')
            ->all();

        $this->assertContains($reqA->id, $visibleIds);
        $this->assertContains($reqB->id, $visibleIds);
    }

    public function test_notification_recipients_match_office_and_department(): void
    {
        $office = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept A', 'code' => 'A']);
        $deptB = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept B', 'code' => 'B']);

        $ucA = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $ucA->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $deptA->id],
        ]);

        $ucB = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $ucB->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $deptB->id],
        ]);

        $recipients = RequisitionNotificationRecipients::unitConsolidatorsForOffice($office->id, $deptA->id);

        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->contains('id', $ucA->id));
        $this->assertFalse($recipients->contains('id', $ucB->id));
    }

    public function test_consumption_scope_unions_assignment_ids(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $officeA->id, 'name' => 'Dept A', 'code' => 'A']);
        $deptB = Department::query()->create(['office_id' => $officeB->id, 'name' => 'Dept B', 'code' => 'B']);

        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $uc->syncOfficeAssignments([
            ['office_id' => $officeA->id, 'department_id' => $deptA->id],
            ['office_id' => $officeB->id, 'department_id' => $deptB->id],
        ]);

        $scope = $uc->getConsumptionScope();

        $this->assertEqualsCanonicalizing([$officeA->id, $officeB->id], $scope['office_ids']);
        $this->assertEqualsCanonicalizing([$deptA->id, $deptB->id], $scope['department_ids']);
    }
}
