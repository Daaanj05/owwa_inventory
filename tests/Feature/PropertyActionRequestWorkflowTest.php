<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Disposal;
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
use App\Services\AcquisitionUnitService;
use App\Services\PropertyActionRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyActionRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_disposal_workflow_creates_disposal_and_retires_unit(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_DISPOSAL,
            'reason_code' => 'unserviceable',
            'reason_detail' => 'Broken screen.',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ], $issuance, $unit);

        $workflow = app(PropertyActionRequestWorkflowService::class);

        $workflow->submit($request->fresh());
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_UC, $request->fresh()->status);

        $workflow->approveByUnitConsolidator($request->fresh(), $uc);
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $request->fresh()->status);

        $workflow->approveBySupplyCustodian($request->fresh(), $custodian);
        $this->assertSame(PropertyActionRequest::STATUS_APPROVED, $request->fresh()->status);

        $propertyNumber = $issuance->property_number;

        $workflow->execute($request->fresh(), $custodian);

        $request->refresh();
        $line = $request->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(PropertyActionRequest::STATUS_EXECUTED, $request->status);
        $this->assertNotNull($line->disposal_id);
        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
        $this->assertSame($propertyNumber, $issuance->fresh()->property_number);
        $this->assertNotNull($issuance->fresh()->custody_ended_at);
        $this->assertSame('disposal', $issuance->fresh()->custody_end_type);
        $this->assertSame($unit->id, Disposal::query()->find($line->disposal_id)?->inventory_unit_id);
        $this->assertSame('Approved — awaiting item', (new PropertyActionRequest(['status' => PropertyActionRequest::STATUS_APPROVED]))->statusLabel());
        $this->assertSame('Received & routed', $request->statusLabel());
    }

    public function test_receive_and_route_dispose_overrides_return_action_type(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        app(PropertyActionRequestWorkflowService::class)->receiveAndRoute(
            $request,
            $custodian,
            PropertyActionRequest::OUTCOME_DISPOSE,
        );

        $request->refresh();
        $line = $request->lines()->first();

        $this->assertSame(PropertyActionRequest::STATUS_EXECUTED, $request->status);
        $this->assertNotNull($line?->disposal_id);
        $this->assertNull($line?->transfer_id);
        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
    }

    public function test_return_to_stock_can_reset_estimated_useful_life(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();
        $item = $issuance->item;
        $this->assertNotNull($item);
        $item->update(['estimated_useful_life' => '2 years']);

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        app(PropertyActionRequestWorkflowService::class)->receiveAndRoute(
            $request,
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
            '5 years',
        );

        $this->assertSame('5 years', $item->fresh()->estimated_useful_life);
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $unit->fresh()->status);
        $this->assertSame($unit->property_number, $issuance->fresh()->property_number);
        $this->assertNotNull($request->fresh()->lines->first()?->transfer_id);
    }

    public function test_transfer_outcome_sets_custody_end_type_transfer(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'needs_repair',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        $this->assertSame(PropertyActionRequest::OUTCOME_TRANSFER, $request->suggestedReceiveOutcome());

        app(PropertyActionRequestWorkflowService::class)->receiveAndRoute(
            $request,
            $custodian,
            PropertyActionRequest::OUTCOME_TRANSFER,
        );

        $this->assertSame('transfer', $issuance->fresh()->custody_end_type);
        $this->assertNotNull($request->fresh()->linkedTransferId());
    }

    public function test_return_workflow_restores_unit_to_office_stock(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        $propertyNumber = $issuance->property_number;

        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        $request->refresh();
        $line = $request->lines->first();
        $transfer = $line?->transfer_id ? Transfer::query()->find($line->transfer_id) : null;

        $this->assertSame(PropertyActionRequest::STATUS_EXECUTED, $request->status);
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $unit->fresh()->status);
        $this->assertNull($unit->fresh()->issuance_id);
        $this->assertSame($issuance->office_id, $unit->fresh()->office_id);
        $this->assertSame($propertyNumber, $issuance->fresh()->property_number);
        $this->assertNull($line?->disposal_id);
        $this->assertNotNull($line?->transfer_id);
        $this->assertNotNull($transfer);
        $this->assertSame('return', $transfer->transfer_type);
        $this->assertSame($issuance->office_id, $transfer->to_office_id);
        $this->assertNotNull($issuance->fresh()->custody_ended_at);
        $this->assertSame('return', $issuance->fresh()->custody_end_type);
        $this->assertSame($request->reference_code, $issuance->fresh()->custody_end_reference);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRequestWithLine(array $attributes, Issuance $issuance, InventoryUnit $unit): PropertyActionRequest
    {
        $request = PropertyActionRequest::query()->create($attributes);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'inventory_unit_id' => $unit->id,
            'sort_order' => 0,
        ]);

        return $request->fresh(['lines']);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Issuance, 4: InventoryUnit}
     */
    private function seedPropertyContext(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

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
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-ACT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 2500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->first();
        $this->assertNotNull($unit);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-ACT-1',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-ACT-001',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 2500,
            'amount' => 2500,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => $unit->property_number,
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        return [$employee, $uc, $custodian, $issuance, $unit->fresh()];
    }
}
