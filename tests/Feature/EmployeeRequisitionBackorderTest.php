<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Actions\EmployeeRequisitionActions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\RequisitionLineFulfillmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRequisitionBackorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_snapshots_stock_at_request_on_lines(): void
    {
        $regionalOffice = Office::factory()->create(['is_regional_supply' => true]);
        $employeeOffice = Office::factory()->create(['is_regional_supply' => false]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $employeeOffice->id,
        ]);

        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $employeeOffice->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
            'transaction_number' => '2026-01-EMP-99',
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'remarks' => 'Need supplies urgently',
        ]);

        $this->actingAs($employee);

        EmployeeRequisitionActions::submitRecord($requisition->fresh());

        $line = $requisition->fresh()->items->first();
        $this->assertNotNull($line);
        $this->assertNotNull($line->stock_at_request);
        $this->assertSame(Requisition::STATUS_PENDING, $requisition->fresh()->status);
    }

    public function test_line_is_backordered_when_stock_at_request_is_zero(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
            'transaction_number' => '2026-01-EMP-88',
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 3,
            'stock_at_request' => 0,
        ]);

        $this->assertTrue($line->isBackordered());
        $this->assertSame(RequisitionLineFulfillmentState::BACKORDERED, $line->fulfillmentState());
        $this->assertSame('Backordered', $line->fulfillmentStateLabel());
    }

    public function test_employee_create_modal_mentions_zero_stock_submission(): void
    {
        Office::factory()->create(['is_regional_supply' => true]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => Office::factory()->create()->id,
        ]);

        $this->actingAs($employee);

        $description = RequisitionForm::createModalDescription();

        $this->assertStringContainsString('submit even when Available is 0', $description);
    }
}
