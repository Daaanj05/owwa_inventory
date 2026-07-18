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

    public function test_partial_return_uses_line_quantity_and_keeps_remaining_custody(): void
    {
        [
            'request' => $request,
            'issuance' => $issuance,
            'units' => $units,
            'custodian' => $custodian,
            'supplyOffice' => $supplyOffice,
        ] = $this->seedApprovedReturn(issuanceQuantity: 3);

        PropertyActionRequestLine::query()
            ->where('property_action_request_id', $request->id)
            ->update(['quantity' => 1]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines.issuance', 'office']),
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );

        $issuance->refresh();
        $this->assertNull($issuance->custody_ended_at);
        $this->assertSame(2, (int) $issuance->quantity);

        $transfer = Transfer::query()->where('transfer_type', 'return')->latest('id')->first();
        $this->assertNotNull($transfer);
        $this->assertSame(1, (int) $transfer->quantity);

        $restored = collect($units)->filter(
            fn (InventoryUnit $unit): bool => $unit->fresh()->status === InventoryUnit::STATUS_IN_STOCK
                && (int) $unit->fresh()->office_id === (int) $supplyOffice->id,
        );
        $this->assertCount(1, $restored);

        $stillIssued = collect($units)->filter(
            fn (InventoryUnit $unit): bool => $unit->fresh()->status === InventoryUnit::STATUS_ISSUED,
        );
        $this->assertCount(2, $stillIssued);
    }

    public function test_full_return_quantity_ends_custody(): void
    {
        [
            'request' => $request,
            'issuance' => $issuance,
            'custodian' => $custodian,
        ] = $this->seedApprovedReturn(issuanceQuantity: 2);

        PropertyActionRequestLine::query()
            ->where('property_action_request_id', $request->id)
            ->update(['quantity' => 2]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines.issuance', 'office']),
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );

        $issuance->refresh();
        $this->assertNotNull($issuance->custody_ended_at);
        $this->assertSame(2, (int) $issuance->quantity);
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
    private function seedApprovedReturn(int $issuanceQuantity): array
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
            'property_number' => $propertyNumber,
        ]);

        $units = [];
        for ($i = 0; $i < $issuanceQuantity; $i++) {
            $units[] = InventoryUnit::query()->create([
                'acquisition_id' => $acquisition->id,
                'item_id' => $item->id,
                'office_id' => $deptOffice->id,
                'property_number' => $propertyNumber,
                'status' => InventoryUnit::STATUS_ISSUED,
                'issuance_id' => $issuance->id,
                'unit_cost' => 4500,
            ]);
        }

        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAR-QTY-'.$issuanceQuantity,
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $supplyOffice->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'inventory_unit_id' => $units[0]->id,
            'quantity' => $issuanceQuantity,
            'sort_order' => 0,
        ]);

        return [
            'request' => $request,
            'issuance' => $issuance,
            'units' => $units,
            'custodian' => $custodian,
            'supplyOffice' => $supplyOffice,
        ];
    }
}
