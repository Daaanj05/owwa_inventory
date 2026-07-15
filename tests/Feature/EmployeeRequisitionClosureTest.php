<?php

namespace Tests\Feature;

use App\Models\Distribution;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use App\Support\EmployeeRequisitionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeRequisitionClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_distribution_does_not_close_employee_requisition(): void
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
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-2001',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        Distribution::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertNull($requisition->closed_at);
        $this->assertSame('Partially distributed — Awaiting balance', EmployeeRequisitionStatus::label($requisition));
        Notification::assertNothingSent();
    }

    public function test_full_distribution_closes_employee_requisition(): void
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
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-2003',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        Distribution::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertNotNull($requisition->closed_at);
        $this->assertSame('Distributed 5 of 5', $requisition->fulfillment_summary);
        $this->assertSame('Fully distributed — Closed', EmployeeRequisitionStatus::label($requisition));
        Notification::assertSentTo($employee, \App\Notifications\RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_distribution_closes_against_uc_endorsed_quantity_not_original_request(): void
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
        $item = Item::factory()->create();

        $consolidated = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
            'reference_code' => 'RIS-END-1',
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-2099',
            'compiled_into_requisition_id' => $consolidated->id,
            'endorsed_at' => now(),
            'endorsed_by' => $uc->id,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $requisition->id,
            'requisition_item_id' => $line->id,
            'requested_by_user_id' => $employee->id,
            'item_id' => $item->id,
            'requested_quantity' => 10,
            'endorsed_quantity' => 6,
        ]);

        Distribution::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 6,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertSame(6, EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition));
        $this->assertNotNull($requisition->closed_at);
        $this->assertSame('Distributed 6 of 6', $requisition->fulfillment_summary);
        $this->assertSame('Fully distributed — Closed', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_distribution_auto_matches_open_employee_requisition_when_not_linked(): void
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
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-2002',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $distribution = Distribution::query()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $requisition->refresh();
        $distribution->refresh();

        $this->assertSame($requisition->id, $distribution->requisition_id);
        $this->assertNotNull($requisition->closed_at);
        $this->assertSame('Fully distributed — Closed', EmployeeRequisitionStatus::label($requisition));
    }
}
