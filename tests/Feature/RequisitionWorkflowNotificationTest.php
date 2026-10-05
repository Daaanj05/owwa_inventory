<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\RequisitionFulfillmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RequisitionWorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_requisition_create_notifies_unit_consolidator_not_custodian(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        Notification::assertNotSentTo($custodian, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertSentTo(
            User::query()->where('role', User::ROLE_UNIT_CONSOLIDATOR)->where('office_id', $office->id)->first(),
            RequisitionWorkflowDatabaseNotification::class,
        );
    }

    public function test_custodian_issue_lines_notifies_unit_consolidator(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-NOTIF-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 100,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0099',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 2,
            ],
        ], now()->toDateString());

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
        $this->assertDatabaseHas(Issuance::class, [
            'requisition_id' => $requisition->id,
            'quantity' => 2,
        ]);
    }

    public function test_consolidated_requisition_notifies_regional_custodian_only(): void
    {
        Notification::fake();

        $regionalOffice = Office::factory()->create(['is_regional_supply' => true]);
        $otherOffice = Office::factory()->create();

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $regionalOffice->id,
        ]);
        $regionalCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regionalOffice->id,
        ]);
        $otherCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $otherOffice->id,
        ]);

        Requisition::query()->create([
            'reference_code' => '2026-01-0500',
            'office_id' => $regionalOffice->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        Notification::assertSentTo($regionalCustodian, RequisitionWorkflowDatabaseNotification::class);
        Notification::assertNotSentTo($otherCustodian, RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_zero_issue_with_remarks_acknowledges_backorder_without_accepting_ris(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0600',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'stock_at_request' => 0,
        ]);

        $result = app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 0,
                'issue_remarks' => 'Awaiting regional restock',
            ],
        ], now()->toDateString());

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['acknowledged']);
        $this->assertSame(Requisition::STATUS_PENDING, $requisition->fresh()->status);
        $this->assertSame('Awaiting regional restock', $line->fresh()->issue_remarks);
        $this->assertSame(0, (int) $line->fresh()->stock_available);

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
    }

    public function test_fulfilled_uc_requisition_notification_opens_the_sent_tab_for_that_department(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $administrative = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Administrative Division',
            'code' => 'ADM-NOTIF',
        ]);
        $finance = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FIN-NOTIF',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $administrative->id,
            'email_verified_at' => now(),
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $administrative->id],
            ['office_id' => $office->id, 'department_id' => $finance->id],
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0203',
            'transaction_number' => '2026-10-0203',
            'office_id' => $office->id,
            'department_id' => $finance->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $notification = new RequisitionWorkflowDatabaseNotification(
            'Requisition fulfilled by Supply Custodian',
            '2026-10-0203 — '.$office->name,
            $requisition->id,
        );
        $actionUrl = $notification->toDatabase($uc)['actions'][0]['url'] ?? '';

        $this->assertStringContainsString('uc=sent', $actionUrl);
        $this->assertStringContainsString('uc_office='.$office->id, $actionUrl);
        $this->assertStringContainsString('uc_dept='.$finance->id, $actionUrl);
        $this->assertSame(
            RequisitionResource::viewModalUrl($requisition, [
                'uc' => 'sent',
                'uc_office' => $office->id,
                'uc_dept' => $finance->id,
            ]),
            $actionUrl,
        );

        $this->actingAs($uc);

        Livewire::withQueryParams([
            'tableAction' => 'view',
            'tableActionRecord' => $requisition->id,
        ])->test(ListRequisitions::class)
            ->assertSet('ucTab', 'sent')
            ->assertSet('ucOfficeId', $office->id)
            ->assertSet('ucDepartmentId', $finance->id);
    }
}
