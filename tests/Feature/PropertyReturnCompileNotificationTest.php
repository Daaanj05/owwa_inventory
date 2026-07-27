<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\IssuanceNotificationService;
use App\Services\PropertyActionRequestCompileService;
use App\Services\PropertyActionRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PropertyReturnCompileNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_property_return_submit_notifies_office_unit_consolidators(): void
    {
        Notification::fake();

        [$employee, $uc, $request] = $this->seedPendingDraftReturn();

        app(PropertyActionRequestWorkflowService::class)->submit($request);

        Notification::assertSentTo(
            $uc,
            RequisitionWorkflowDatabaseNotification::class,
            function (RequisitionWorkflowDatabaseNotification $notification) use ($request): bool {
                return $notification->title === 'Employee property return submitted'
                    && $notification->propertyActionRequestId === (int) $request->id;
            },
        );
    }

    public function test_property_return_notification_uses_property_return_modal_url(): void
    {
        $notification = new RequisitionWorkflowDatabaseNotification(
            'Employee property return submitted',
            'Body',
            propertyActionRequestId: 42,
        );

        $payload = $notification->toDatabase(new User);
        $actionUrl = $payload['actions'][0]['url'] ?? null;

        $this->assertSame(
            PropertyActionRequestResource::viewModalUrl(42),
            $actionUrl,
        );
    }

    public function test_issuance_with_requisition_skips_forbidden_issuance_deep_link_notification(): void
    {
        Notification::fake();

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

        $issuance = Issuance::query()->create([
            'reference_code' => 'ICS-SKIP-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 10,
            'amount' => 10,
            'issuance_date' => now(),
            'issued_by' => $employee->id,
            'issued_to' => $employee->id,
            'requisition_id' => $requisition->id,
        ]);

        app(IssuanceNotificationService::class)->handleCreated($issuance);

        Notification::assertNothingSent();
    }

    public function test_compile_notifies_supply_custodians_with_batch_link(): void
    {
        Notification::fake();

        [$employee, $uc, $request] = $this->seedPendingDraftReturn();
        $regional = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
        ]);

        $workflow = app(PropertyActionRequestWorkflowService::class);
        $workflow->submit($request);
        $workflow->approveByUnitConsolidator($request->fresh(), $uc);

        Notification::fake();

        $batch = app(PropertyActionRequestCompileService::class)
            ->createCompiledSubmission($uc, [$request->fresh()]);

        Notification::assertSentTo(
            $custodian,
            RequisitionWorkflowDatabaseNotification::class,
            function (RequisitionWorkflowDatabaseNotification $notification) use ($batch): bool {
                return $notification->title === 'Property return awaiting SC approval'
                    && $notification->propertyActionRequestId === (int) $batch->id;
            },
        );

        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $batch->status);
    }

    /**
     * @return array{0: User, 1: User, 2: PropertyActionRequest}
     */
    protected function seedPendingDraftReturn(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
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
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::withoutEvents(fn () => Issuance::query()->create([
            'reference_code' => 'ICS-RET-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'amount' => 100,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'requisition_id' => $requisition->id,
        ]));

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'sort_order' => 0,
            'quantity' => 1,
        ]);

        return [$employee, $uc, $request->fresh()];
    }
}
