<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\RequisitionFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
}
