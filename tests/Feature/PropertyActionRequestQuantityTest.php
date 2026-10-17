<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PropertyActionRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyActionRequestQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_return_frees_only_the_selected_unit_and_keeps_remaining_custody(): void
    {
        [
            'request' => $request,
            'issuance' => $issuance,
            'units' => $units,
            'custodian' => $custodian,
            'supplyOffice' => $supplyOffice,
        ] = $this->seedApprovedReturn(issuanceQuantity: 3, returnedUnitIndexes: [1]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines.issuance', 'office']),
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );

        $issuance->refresh();
        $this->assertNull($issuance->custody_ended_at);
        $this->assertSame(3, (int) $issuance->quantity);
        $this->assertSame(1, (int) $issuance->returned_quantity);
        $this->assertSame(2, $issuance->remainingQuantity());

        $transfer = Transfer::query()->where('transfer_type', 'return')->sole();
        $this->assertSame(1, (int) $transfer->quantity);
        $this->assertSame($units[1]->id, (int) $transfer->inventory_unit_id);
        $this->assertSame($units[1]->property_number, $transfer->property_number);

        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $units[1]->fresh()->status);
        $this->assertSame((int) $supplyOffice->id, (int) $units[1]->fresh()->office_id);
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $units[0]->fresh()->status);
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $units[2]->fresh()->status);
    }

    public function test_returning_every_unit_ends_custody(): void
    {
        [
            'request' => $request,
            'issuance' => $issuance,
            'custodian' => $custodian,
        ] = $this->seedApprovedReturn(issuanceQuantity: 2, returnedUnitIndexes: [0, 1]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines.issuance', 'office']),
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );

        $issuance->refresh();
        $this->assertNotNull($issuance->custody_ended_at);
        $this->assertSame('return', $issuance->custody_end_type);
        $this->assertSame(2, (int) $issuance->quantity);
        $this->assertSame(2, (int) $issuance->returned_quantity);
        $this->assertSame(2, Transfer::query()->where('transfer_type', 'return')->count());
    }

    public function test_executing_a_line_whose_unit_was_already_returned_is_blocked(): void
    {
        [
            'request' => $request,
            'units' => $units,
            'custodian' => $custodian,
        ] = $this->seedApprovedReturn(issuanceQuantity: 2, returnedUnitIndexes: [0]);

        $units[0]->update(['status' => InventoryUnit::STATUS_IN_STOCK, 'issuance_id' => null]);

        $this->expectException(\InvalidArgumentException::class);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines.issuance', 'office']),
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );
    }

    /**
     * @return array{
     *     request: PropertyActionRequest,
     *     issuance: Issuance,
     *     units: list<InventoryUnit>,
     *     custodian: User,
     *     supplyOffice: Office
     * }
     */
    /**
     * @param  list<int>  $returnedUnitIndexes
     */
    private function seedApprovedReturn(int $issuanceQuantity, array $returnedUnitIndexes): array
    {
        $supplyOffice = Office::factory()->create(['name' => 'Regional Supply', 'code' => 'RS']);
        $deptOffice = Office::factory()->create(['name' => 'Satellite', 'code' => 'SAT']);
        $department = Department::query()->create([
            'office_id' => $deptOffice->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi'],
        );
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'office_id' => $deptOffice->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-QTY-'.$issuanceQuantity,
            'item_id' => $item->id,
            'office_id' => $deptOffice->id,
            'quantity' => $issuanceQuantity,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-09-'.str_pad((string) $issuanceQuantity, 4, '0', STR_PAD_LEFT),
            'office_id' => $deptOffice->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $propertyNumber = 'SPLV-2026-QTY-'.$issuanceQuantity;
        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-09-'.str_pad((string) ($issuanceQuantity + 100), 4, '0', STR_PAD_LEFT),
            'office_id' => $deptOffice->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => $issuanceQuantity,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $employee->id,
            'property_number' => $propertyNumber.'-0',
        ]);

        $units = [];
        for ($i = 0; $i < $issuanceQuantity; $i++) {
            $units[] = InventoryUnit::query()->create([
                'acquisition_id' => $acquisition->id,
                'item_id' => $item->id,
                'office_id' => $deptOffice->id,
                'property_number' => $propertyNumber.'-'.$i,
                'status' => InventoryUnit::STATUS_ISSUED,
                'issuance_id' => $issuance->id,
                'unit_cost' => 4500,
            ]);
        }

        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAR-QTY-'.$issuanceQuantity,
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'serviceable_not_needed',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $supplyOffice->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ]);

        foreach ($returnedUnitIndexes as $sort => $index) {
            PropertyActionRequestLine::query()->create([
                'property_action_request_id' => $request->id,
                'issuance_id' => $issuance->id,
                'inventory_unit_id' => $units[$index]->id,
                'quantity' => 1,
                'sort_order' => $sort,
            ]);
        }

        return [
            'request' => $request,
            'issuance' => $issuance,
            'units' => $units,
            'custodian' => $custodian,
            'supplyOffice' => $supplyOffice,
        ];
    }
}
