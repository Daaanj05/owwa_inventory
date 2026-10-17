<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestTableActions;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
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
use App\Services\InventoryStockService;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            'department_id' => $issuance->department_id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ], $issuance, $unit);

        $workflow = app(PropertyActionRequestWorkflowService::class);

        $workflow->submit($request->fresh());
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_UC, $request->fresh()->status);

        $workflow->approveByUnitConsolidator($request->fresh(), $uc);
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_UC, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->uc_approved_at);

        $batch = app(\App\Services\PropertyActionRequestCompileService::class)
            ->createCompiledSubmissions($uc, [$request->fresh()])
            ->sole();
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $batch->status);
        $this->assertSame($batch->id, $request->fresh()->compiled_into_property_action_request_id);
        $this->assertSame(PropertyActionRequest::STATUS_PENDING_SC, $request->fresh()->status);

        $workflow->approveBySupplyCustodian($batch->fresh(), $custodian);
        $this->assertSame(PropertyActionRequest::STATUS_APPROVED, $batch->fresh()->status);
        $this->assertSame(PropertyActionRequest::STATUS_APPROVED, $request->fresh()->status);

        $propertyNumber = $issuance->property_number;

        $workflow->execute($batch->fresh(), $custodian);

        $batch->refresh();
        $line = $batch->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(PropertyActionRequest::STATUS_EXECUTED, $batch->status);
        $this->assertSame(PropertyActionRequest::STATUS_EXECUTED, $request->fresh()->status);
        $this->assertSame(PropertyActionRequest::OUTCOME_DISPOSE, $line->outcome);
        $this->assertNotNull($line->disposal_id);
        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
        $this->assertSame($propertyNumber, $issuance->fresh()->property_number);
        $this->assertNotNull($issuance->fresh()->custody_ended_at);
        $this->assertSame('disposal', $issuance->fresh()->custody_end_type);
        $this->assertSame($unit->id, Disposal::query()->find($line->disposal_id)?->inventory_unit_id);
        $this->assertSame('Approved — awaiting item', (new PropertyActionRequest(['status' => PropertyActionRequest::STATUS_APPROVED]))->statusLabel());
        $this->assertSame('Received & routed', $batch->statusLabel());
    }

    public function test_receive_and_route_dispose_overrides_return_action_type(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'serviceable_not_needed',
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
            'reason_code' => 'serviceable_not_needed',
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

    public function test_suggested_outcome_follows_reason(): void
    {
        $outcome = fn (string $actionType, string $reason): string => (new PropertyActionRequest([
            'action_type' => $actionType,
            'reason_code' => $reason,
        ]))->suggestedReceiveOutcome();

        $this->assertSame(PropertyActionRequest::OUTCOME_RETURN_TO_STOCK, $outcome(PropertyActionRequest::ACTION_RETURN, 'serviceable_not_needed'));
        $this->assertSame(PropertyActionRequest::OUTCOME_RETURN_TO_STOCK, $outcome(PropertyActionRequest::ACTION_RETURN, 'officer_separation'));
        $this->assertSame(PropertyActionRequest::OUTCOME_REPAIR, $outcome(PropertyActionRequest::ACTION_RETURN, 'needs_repair'));
        $this->assertSame(PropertyActionRequest::OUTCOME_DISPOSE, $outcome(PropertyActionRequest::ACTION_RETURN, 'unserviceable'));
        $this->assertSame(PropertyActionRequest::OUTCOME_DISPOSE, $outcome(PropertyActionRequest::ACTION_REPLACEMENT, 'obsolete'));
        $this->assertSame(PropertyActionRequest::OUTCOME_DISPOSE, $outcome(PropertyActionRequest::ACTION_DISPOSAL, 'lost'));
    }

    public function test_repair_outcome_holds_unit_out_of_stock(): void
    {
        [, , $custodian, $issuance, $unit, $request] = $this->routeUnitToRepair();

        $unit->refresh();
        $this->assertSame(InventoryUnit::STATUS_UNDER_REPAIR, $unit->status);
        $this->assertNull($unit->issuance_id);
        $this->assertSame($issuance->id, $unit->repair_source_issuance_id);
        $this->assertSame('repair', $issuance->fresh()->custody_end_type);
        $this->assertSame(1, (int) $issuance->fresh()->returned_quantity);
        $this->assertNull($request->fresh()->linkedTransferId());
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, $this->availableStock($issuance));
        $this->assertTrue($request->fresh()->underRepairUnitsQuery()->whereKey($unit->id)->exists());
    }

    public function test_mark_repaired_returns_unit_to_supply_stock(): void
    {
        [, , $custodian, $issuance, $unit, $request] = $this->routeUnitToRepair();

        $transfer = app(PropertyActionRequestWorkflowService::class)->markRepairedToStock($unit->fresh(), $custodian);

        $unit->refresh();
        $this->assertSame('return', $transfer->transfer_type);
        $this->assertSame($unit->id, (int) $transfer->inventory_unit_id);
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $unit->status);
        $this->assertNull($unit->repair_source_issuance_id);
        $this->assertSame(1, $this->availableStock($issuance));
        $this->assertFalse($request->fresh()->underRepairUnitsQuery()->exists());
    }

    public function test_reissue_repaired_unit_creates_new_issuance_for_original_employee_without_changing_stock(): void
    {
        [$employee, , $custodian, $issuance, $unit] = $this->routeUnitToRepair();

        $stockBefore = $this->availableStock($issuance);
        $reissued = app(PropertyActionRequestWorkflowService::class)->reissueRepairedUnit($unit->fresh(), $custodian);

        $unit->refresh();
        $this->assertNotSame($issuance->id, $reissued->id);
        $this->assertSame($employee->id, (int) $reissued->issued_to);
        $this->assertSame($issuance->requisition_id, $reissued->requisition_id);
        $this->assertSame(1, (int) $reissued->quantity);
        $this->assertSame([$unit->property_number], $reissued->issued_property_numbers);
        $this->assertNotSame($issuance->reference_code, $reissued->reference_code);
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $unit->status);
        $this->assertSame($reissued->id, $unit->issuance_id);
        $this->assertSame($stockBefore, $this->availableStock($issuance));
    }

    public function test_unrepairable_unit_is_disposed_without_deducting_stock(): void
    {
        [, , $custodian, $issuance, $unit] = $this->routeUnitToRepair();

        $disposal = app(PropertyActionRequestWorkflowService::class)
            ->disposeUnrepairableUnit($unit->fresh(), $custodian, 'Motherboard failure');

        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
        $this->assertSame($issuance->id, (int) $disposal->par_issuance_id);
        $this->assertSame(0, $this->availableStock($issuance));
    }

    public function test_resolving_a_unit_that_is_not_under_repair_is_rejected(): void
    {
        [, , $custodian, , $unit] = $this->seedPropertyContext();

        $this->expectException(\InvalidArgumentException::class);

        app(PropertyActionRequestWorkflowService::class)->markRepairedToStock($unit, $custodian);
    }

    public function test_dispose_of_issued_unit_does_not_deduct_stock_again(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'unserviceable',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        $this->assertSame(0, $this->availableStock($issuance));

        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
        $this->assertSame(0, $this->availableStock($issuance));
    }

    public function test_repair_line_outcome_follows_unit_resolution(): void
    {
        [, , $custodian, , $unit, $request] = $this->routeUnitToRepair();
        $line = fn (): PropertyActionRequestLine => $request->lines()->with('inventoryUnit')->firstOrFail();

        $this->assertSame('Under repair', $line()->outcomeLabel());
        $this->assertSame('warning', $line()->outcomeColor());
        $this->assertTrue($line()->isUnderRepair());

        app(PropertyActionRequestWorkflowService::class)->reissueRepairedUnit($unit->fresh(), $custodian);

        $this->assertSame('Repaired — re-issued', $line()->outcomeLabel());
        $this->assertSame('success', $line()->outcomeColor());
        $this->assertFalse($line()->isUnderRepair());
    }

    public function test_repair_line_outcome_labels_for_stock_and_unrepairable(): void
    {
        [, , $custodian, , $unit, $request] = $this->routeUnitToRepair();
        app(PropertyActionRequestWorkflowService::class)->markRepairedToStock($unit->fresh(), $custodian);
        $this->assertSame('Repaired — in stock', $request->lines()->with('inventoryUnit')->firstOrFail()->outcomeLabel());

        [, , $custodian, , $unit, $request] = $this->routeUnitToRepair('2');
        app(PropertyActionRequestWorkflowService::class)->disposeUnrepairableUnit($unit->fresh(), $custodian, 'Beyond repair');
        $line = $request->lines()->with('inventoryUnit')->firstOrFail();
        $this->assertSame('Unrepairable — disposed', $line->outcomeLabel());
        $this->assertSame('danger', $line->outcomeColor());
    }

    public function test_with_units_under_repair_scope_only_lists_unresolved_repairs(): void
    {
        [, , $custodian, , $unit, $repairRequest] = $this->routeUnitToRepair();
        [, , , , , $resolvedRequest] = $this->routeUnitToRepair('2');
        app(PropertyActionRequestWorkflowService::class)
            ->markRepairedToStock($resolvedRequest->lines()->firstOrFail()->inventoryUnit, $custodian);

        $ids = PropertyActionRequest::query()->withUnitsUnderRepair()->pluck('id')->all();

        $this->assertSame([$repairRequest->id], $ids);
    }

    public function test_supply_custodian_resolves_repair_from_view_modal(): void
    {
        [, $uc, $custodian, $issuance, $unit, $request] = $this->routeUnitToRepair();

        Livewire::actingAs($custodian)
            ->test(ListPropertyActionRequests::class)
            ->filterTable('under_repair')
            ->assertCanSeeTableRecords([$request])
            ->assertActionVisible([TestAction::make('view')->table($request), TestAction::make('scResolveRepair')])
            ->callAction([TestAction::make('view')->table($request), TestAction::make('scResolveRepair')], [
                'inventory_unit_id' => $unit->id,
                'resolution' => 'return_to_stock',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Unit returned to supply stock');

        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $unit->fresh()->status);
        $this->assertSame(1, $this->availableStock($issuance));

        Livewire::actingAs($custodian)
            ->test(ListPropertyActionRequests::class)
            ->assertActionHidden([TestAction::make('view')->table($request), TestAction::make('scResolveRepair')])
            ->filterTable('under_repair')
            ->assertCanNotSeeTableRecords([$request]);
    }

    public function test_resolve_repair_is_hidden_from_unit_consolidator(): void
    {
        [, $uc, , , , $request] = $this->routeUnitToRepair();

        $this->actingAs($uc);
        $action = PropertyActionRequestTableActions::scResolveRepairAction()->record($request);

        $this->assertFalse($action->isVisible());
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Issuance, 4: InventoryUnit, 5: PropertyActionRequest}
     */
    private function routeUnitToRepair(string $suffix = '1'): array
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext($suffix);

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'needs_repair',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ], $issuance, $unit);

        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        return [$employee, $uc, $custodian, $issuance, $unit, $request];
    }

    private function availableStock(Issuance $issuance): int
    {
        $stock = app(InventoryStockService::class);
        $stock->forgetMovementTotalsCache();

        return $stock->getStock((int) $issuance->item_id, (int) $issuance->office_id);
    }

    public function test_return_workflow_restores_unit_to_office_stock(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createRequestWithLine([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'serviceable_not_needed',
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
    private function seedPropertyContext(string $suffix = '1'): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::query()->where('name', 'Semi-Expendable')->first()
            ?? ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-ACT-'.$suffix,
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
            'reference_code' => 'REQ-ACT-'.$suffix,
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-ACT-00'.$suffix,
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
