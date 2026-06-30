<?php

namespace Tests\Unit;

use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\RequisitionWorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RequisitionWorkflowNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RequisitionWorkflowNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RequisitionWorkflowNotificationService::class);
    }

    public function test_joe_create_notifies_unit_consolidator_not_custodian(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->service->handleCreated($requisition);

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertNotSentTo($custodian, RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_unit_consolidator_create_notifies_custodian(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->service->handleCreated($requisition);

        Notification::assertSentTo($custodian, RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_unit_consolidator_reject_notifies_employee_with_mail(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_REJECTED,
            'remarks' => 'Not approved for this quarter.',
        ]);

        $this->service->handleUpdated($requisition, Requisition::STATUS_PENDING);

        Notification::assertSentTo($employee, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertSentTo($employee, RequisitionRejectedMailNotification::class);
    }

    public function test_custodian_reject_notifies_unit_consolidator_with_mail(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_REJECTED,
            'remarks' => 'Insufficient stock.',
        ]);

        $this->service->handleCustodianRejected($requisition);

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertSentTo($uc, RequisitionRejectedMailNotification::class);
    }

    public function test_status_update_without_change_sends_no_notifications(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->service->handleUpdated($requisition, Requisition::STATUS_PENDING);

        Notification::assertNothingSent();
    }
}
