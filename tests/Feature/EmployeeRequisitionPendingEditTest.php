<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Actions\EmployeeRequisitionActions;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionOriginalSubmission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeRequisitionPendingEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_edit_pending_but_not_accepted_requisitions(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Pending purpose',
        ]);

        $accepted = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'Accepted purpose',
        ]);

        $this->assertTrue($pending->canEmployeeEdit());
        $this->assertFalse($pending->canEmployeeSubmit());
        $this->assertFalse($accepted->canEmployeeEdit());
    }

    public function test_submit_snapshots_original_submission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
            'purpose' => 'For training',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $this->actingAs($employee);

        EmployeeRequisitionActions::submitRecord($requisition->fresh());

        $requisition->refresh();

        $this->assertSame(Requisition::STATUS_PENDING, $requisition->status);
        $this->assertIsArray($requisition->original_submission);
        $this->assertSame('For training', $requisition->original_submission['purpose'] ?? null);
        $this->assertCount(1, $requisition->original_submission['lines'] ?? []);
        $this->assertFalse($requisition->hasEmployeeContentEdits());
    }

    public function test_pending_content_edit_marks_original_vs_current_and_notifies_uc(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $itemA = Item::factory()->create(['name' => 'Bond Paper']);
        $itemB = Item::factory()->create(['name' => 'Ballpen']);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Original purpose',
            'transaction_number' => 'TXN-1',
            'original_submission' => [
                'purpose' => 'Original purpose',
                'lines' => [
                    [
                        'item_id' => $itemA->id,
                        'item_name' => 'Bond Paper',
                        'quantity' => 2,
                    ],
                ],
            ],
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemA->id,
            'quantity' => 2,
        ]);

        $this->actingAs($employee);

        $requisition->items()->delete();
        $requisition->update([
            'purpose' => 'Updated purpose',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemA->id,
            'quantity' => 5,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemB->id,
            'quantity' => 1,
        ]);

        EmployeeRequisitionActions::handlePendingContentSaved($requisition->fresh());

        $requisition->refresh()->load('items');

        $this->assertSame('Updated purpose', $requisition->purpose);
        $this->assertSame(2, $requisition->items->count());
        $this->assertTrue($requisition->hasEmployeeContentEdits());
        $this->assertNotNull($requisition->content_edited_at);
        $this->assertSame('Original purpose', EmployeeRequisitionOriginalSubmission::originalPurpose($requisition));

        $comparison = EmployeeRequisitionOriginalSubmission::lineComparisonRows($requisition);
        $this->assertNotEmpty($comparison);
        $this->assertTrue(collect($comparison)->contains(fn (array $row): bool => $row['change'] === 'changed'));
        $this->assertTrue(collect($comparison)->contains(fn (array $row): bool => $row['change'] === 'added'));

        Notification::assertSentTo($uc, RequisitionWorkflowDatabaseNotification::class);
    }
}
