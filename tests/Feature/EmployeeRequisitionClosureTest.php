<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use App\Support\EmployeeRequisitionStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeRequisitionClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_issuance_does_not_close_employee_requisition(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
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

        Issuance::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'issued_to' => $employee->id,
            'issued_by' => $custodian->id,
            'issuance_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertNull($requisition->closed_at);
        $this->assertSame('Partially issued — Awaiting balance', EmployeeRequisitionStatus::label($requisition));
        Notification::assertNothingSent();
    }

    public function test_full_issuance_closes_employee_requisition(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
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

        Issuance::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'issued_to' => $employee->id,
            'issued_by' => $custodian->id,
            'issuance_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertNotNull($requisition->closed_at);
        $this->assertSame('Issued 5 of 5', $requisition->fulfillment_summary);
        $this->assertSame('Fully issued — Closed', EmployeeRequisitionStatus::label($requisition));
        Notification::assertSentTo($employee, \App\Notifications\RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_issuance_closes_against_uc_endorsed_quantity_not_original_request(): void
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
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
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

        $endorsement = RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $requisition->id,
            'requisition_item_id' => $line->id,
            'requested_by_user_id' => $employee->id,
            'item_id' => $item->id,
            'requested_quantity' => 10,
            'endorsed_quantity' => 6,
        ]);

        Issuance::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'consolidated_requisition_id' => $consolidated->id,
            'source_endorsement_id' => $endorsement->id,
            'item_id' => $item->id,
            'quantity' => 6,
            'issued_to' => $employee->id,
            'issued_by' => $custodian->id,
            'issuance_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->assertSame(6, EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition));
        $this->assertNotNull($requisition->closed_at);
        $this->assertSame('Issued 6 of 6', $requisition->fulfillment_summary);
        $this->assertSame('Fully issued — Closed', EmployeeRequisitionStatus::label($requisition));
    }

    public function test_closed_employee_requisition_stays_in_active_tab(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'REQ-ARCH-0001',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        Issuance::query()->create([
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'issued_to' => $employee->id,
            'issued_by' => $custodian->id,
            'issuance_date' => now()->toDateString(),
        ]);

        $requisition->refresh();

        $this->actingAs($employee);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$requisition])
            ->set('activeTab', 'archived')
            ->assertCanNotSeeTableRecords([$requisition]);
    }
}
