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
use App\Models\RequisitionItem;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PropertyReturnDecisionService;
use App\Services\RequisitionFulfillmentService;
use App\Support\IssuableInventoryUnitOptions;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class IssueUnitSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_fifo_selection_links_selected_unit_ids(): void
    {
        [$supplyOffice, $requestingOffice, $item, $custodian, $uc, $units] = $this->seedSemiUnits(3);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0701',
            'office_id' => $requestingOffice->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $selected = app(IssuableInventoryUnitOptions::class)
            ->defaultUnitIds($item->id, $supplyOffice->id, 2);

        $this->assertSame(
            [$units[0]->id, $units[1]->id],
            $selected,
        );

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [[
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 2,
                'inventory_unit_ids' => $selected,
            ]],
            '2026-06-12',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->first();
        $this->assertNotNull($issuance);
        $this->assertSame($units[0]->property_number, $issuance->property_number);
        $this->assertSame(
            [$units[0]->property_number, $units[1]->property_number],
            $issuance->issuedPropertyNumbers(),
        );

        $this->assertSame(InventoryUnit::STATUS_ISSUED, $units[0]->fresh()->status);
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $units[1]->fresh()->status);
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $units[2]->fresh()->status);
        $this->assertSame($issuance->id, $units[0]->fresh()->issuance_id);
        $this->assertSame($issuance->id, $units[1]->fresh()->issuance_id);
    }

    public function test_override_selection_issues_returned_unit_property_number(): void
    {
        [$supplyOffice, $requestingOffice, $item, $custodian, $uc, $units] = $this->seedSemiUnits(2);
        $fresh = $units[0];
        $returned = $units[1];

        $this->issueThenReturnUnitToSupply($returned, $item, $supplyOffice, $requestingOffice, $custodian);

        $returned->refresh();
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $returned->status);
        $this->assertSame($supplyOffice->id, $returned->office_id);

        $options = app(IssuableInventoryUnitOptions::class);
        $this->assertStringContainsString(' — Returned', $options->labelFor($returned));
        $this->assertSame($fresh->property_number, $options->labelFor($fresh));

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0702',
            'office_id' => $requestingOffice->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [[
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 1,
                'inventory_unit_ids' => [$returned->id],
            ]],
            '2026-06-13',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->latest('id')->first();
        $this->assertNotNull($issuance);
        $this->assertSame($returned->property_number, $issuance->property_number);
        $this->assertSame($uc->id, $issuance->issued_to);

        $returned->refresh();
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $returned->status);
        $this->assertSame($issuance->id, $returned->issuance_id);
        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $fresh->fresh()->status);
    }

    public function test_issuing_fails_when_selected_unit_is_no_longer_in_stock(): void
    {
        [$supplyOffice, $requestingOffice, $item, $custodian, $uc, $units] = $this->seedSemiUnits(1);
        $unit = $units[0];

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0703',
            'office_id' => $requestingOffice->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $unit->update(['status' => InventoryUnit::STATUS_ISSUED]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('One or more selected inventory units are no longer in regional stock.');

        app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [[
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 1,
                'inventory_unit_ids' => [$unit->id],
            ]],
            '2026-06-14',
        );
    }

    public function test_consumable_issue_path_ignores_unit_selection(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-CONS-UNITS',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 20,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0704',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [[
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 3,
                'inventory_unit_ids' => [999],
            ]],
            '2026-06-15',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->first();
        $this->assertNotNull($issuance);
        $this->assertSame(3, (int) $issuance->quantity);
        $this->assertNull($issuance->property_number);
        $this->assertSame(0, InventoryUnit::query()->where('issuance_id', $issuance->id)->count());
    }

    /**
     * @return array{0: Office, 1: Office, 2: Item, 3: User, 4: User, 5: list<InventoryUnit>}
     */
    protected function seedSemiUnits(int $count): array
    {
        $supplyOffice = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $requestingOffice = Office::factory()->create([
            'name' => 'Field Office',
            'is_regional_supply' => false,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'estimated_useful_life' => '60 months',
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $requestingOffice->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-UNIT-SEL-'.$count,
            'item_id' => $item->id,
            'office_id' => $supplyOffice->id,
            'quantity' => $count,
            'unit_cost' => 1500,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $custodian->id,
        ]);

        $units = InventoryUnit::query()
            ->where('acquisition_id', $acquisition->id)
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount($count, $units);

        return [$supplyOffice, $requestingOffice, $item, $custodian, $uc, $units];
    }

    protected function issueThenReturnUnitToSupply(
        InventoryUnit $unit,
        Item $item,
        Office $supplyOffice,
        Office $requestingOffice,
        User $custodian,
    ): void {
        $department = Department::query()->create([
            'office_id' => $requestingOffice->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $requestingOffice->id,
            'department_id' => $department->id,
        ]);

        $priorRequisition = Requisition::query()->create([
            'reference_code' => '2026-01-0699',
            'office_id' => $requestingOffice->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $priorIssuance = Issuance::query()->create([
            'requisition_id' => $priorRequisition->id,
            'reference_code' => 'ICS-RET-001',
            'item_id' => $item->id,
            'office_id' => $requestingOffice->id,
            'issued_from_office_id' => $supplyOffice->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 1500,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $custodian->id,
            'issued_to' => $employee->id,
            'property_number' => $unit->property_number,
            'estimated_useful_life' => '60 months',
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $priorIssuance->id,
            'office_id' => $requestingOffice->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAR-RET-UNIT',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'serviceable_not_needed',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $requestingOffice->id,
            'department_id' => $department->id,
            'status' => PropertyActionRequest::STATUS_PENDING_DECISION,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $priorIssuance->id,
            'inventory_unit_id' => $unit->id,
            'quantity' => 1,
            'sort_order' => 0,
        ]);

        $decision = app(PropertyReturnDecisionService::class);
        $transfer = $decision->decideForTransfer($request->fresh(['lines']), $custodian);
        $transfer->forceFill([
            'transfer_type' => Transfer::TYPE_RELOCATE,
            'exported_at' => now(),
        ])->saveQuietly();
        $decision->markTransferApproved($transfer->fresh(), $custodian);
    }
}
