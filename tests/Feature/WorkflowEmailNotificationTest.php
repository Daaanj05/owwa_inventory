<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\PropertyActionRequest;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\WorkflowEmailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Notifications\WorkflowUpdateMailNotification;
use App\Services\InventoryPlanReminderService;
use App\Services\PropertyActionRequestWorkflowService;
use App\Services\RequisitionWorkflowNotificationService;
use App\Support\WorkflowEmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class WorkflowEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_requisition_submit_queues_email_for_unit_consolidator(): void
    {
        Notification::fake();
        config(['queue.default' => 'database']);

        $office = Office::factory()->create(['name' => 'Regional Office IV-A']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'name' => 'Jane Employee',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'email' => 'uc@example.com',
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => '2026-01-5001',
        ]);

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertSentTo($uc, WorkflowUpdateMailNotification::class);

        $log = WorkflowEmailNotification::query()->where('user_id', $uc->id)->first();

        $this->assertNotNull($log);
        $this->assertSame(WorkflowEmailNotification::STATUS_QUEUED, $log->status);
        $this->assertSame((int) $requisition->id, (int) $log->requisition_id);
        $this->assertSame('New requisition from Jane Employee', $log->title);

        $mail = null;
        Notification::assertSentTo(
            $uc,
            WorkflowUpdateMailNotification::class,
            function (WorkflowUpdateMailNotification $notification) use (&$mail): bool {
                $mail = $notification;

                return true;
            },
        );

        $this->assertNotNull($mail);
        $rendered = (string) $mail->toMail($uc)->render();
        $this->assertStringContainsString('Jane Employee', $rendered);
        $this->assertStringContainsString('Regional Office IV-A', $rendered);
        $this->assertStringContainsString('Admin', $rendered);
    }

    public function test_workflow_email_is_skipped_when_recipient_has_no_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => '',
        ]);
        $requisition = Requisition::query()->create([
            'office_id' => Office::factory()->create()->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $log = app(WorkflowEmailNotifier::class)->queue(
            $user,
            'Requisition reviewed',
            'Body',
            ['requisition_id' => (int) $requisition->id],
        );

        $this->assertSame(WorkflowEmailNotification::STATUS_SKIPPED, $log->status);
        Notification::assertNotSentTo($user, WorkflowUpdateMailNotification::class);
    }

    public function test_workflow_email_is_failed_when_mail_transport_throws(): void
    {
        config(['queue.default' => 'sync']);

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
        ]);
        $requisition = Requisition::query()->create([
            'office_id' => Office::factory()->create()->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $failingUser = Mockery::mock($user)->makePartial();
        $failingUser->shouldReceive('notify')
            ->once()
            ->andThrow(new TransportException('Unable to connect to mail server'));

        $log = app(WorkflowEmailNotifier::class)->queue(
            $failingUser,
            'Requisition reviewed',
            'Body',
            ['requisition_id' => (int) $requisition->id],
        );

        $this->assertSame(WorkflowEmailNotification::STATUS_FAILED, $log->status);
        $this->assertSame('Mail transport failed.', $log->failure_message);
    }

    public function test_property_return_submit_logs_email_for_unit_consolidator(): void
    {
        Notification::fake();
        config(['queue.default' => 'sync']);

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'name' => 'Return Employee',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'email' => 'uc-return@example.com',
        ]);

        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAR-EMAIL-1',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'reason_code' => 'serviceable_not_needed',
        ]);

        app(PropertyActionRequestWorkflowService::class)->notifyEmployeeSubmitted($request);

        Notification::assertSentTo($uc, WorkflowUpdateMailNotification::class);

        $log = WorkflowEmailNotification::query()
            ->where('user_id', $uc->id)
            ->where('property_action_request_id', $request->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(WorkflowEmailNotification::STATUS_SENT, $log->status);
        $this->assertSame('Employee property return submitted', $log->title);
    }

    public function test_inventory_schedule_reminder_logs_email_for_custodian(): void
    {
        Notification::fake();
        config(['queue.default' => 'sync']);

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'sc@example.com',
        ]);

        $plan = PhysicalInventoryPlan::factory()->create([
            'status' => PhysicalInventoryPlan::STATUS_APPROVED,
            'title' => 'FY 2026 Count',
        ]);
        PhysicalInventoryPlanLine::factory()->create([
            'physical_inventory_plan_id' => $plan->id,
            'office_id' => $office->id,
            'planned_date' => '2026-06-15',
        ]);

        $result = app(InventoryPlanReminderService::class)
            ->sendDueReminders(Carbon::parse('2026-06-15'));

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTo($custodian, WorkflowUpdateMailNotification::class);

        $log = WorkflowEmailNotification::query()
            ->where('user_id', $custodian->id)
            ->where('physical_inventory_plan_id', $plan->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(WorkflowEmailNotification::STATUS_SENT, $log->status);
        $this->assertStringContainsString('Inventory count today', $log->title);
    }

    public function test_partial_issue_notifies_employee_and_unit_consolidator(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email' => 'emp@example.com',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'email' => 'uc2@example.com',
        ]);
        $item = Item::factory()->create();

        $consolidated = Requisition::query()->create([
            'reference_code' => 'RIS-EMAIL-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $source = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-5002',
            'compiled_into_requisition_id' => $consolidated->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $source->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        Issuance::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $source->id,
            'item_id' => $item->id,
            'quantity' => 4,
            'issued_to' => $employee->id,
            'issued_by' => User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN])->id,
            'issuance_date' => now()->toDateString(),
        ]);

        app(RequisitionWorkflowNotificationService::class)->handleCustodianIssued($consolidated->fresh());

        Notification::assertSentTo(
            $employee,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => $notification->title === 'Requisition partially issued',
        );
        Notification::assertSentTo(
            $uc,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => $notification->title === 'Requisition partially issued',
        );
    }
}
