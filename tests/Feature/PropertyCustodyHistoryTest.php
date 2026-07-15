<?php

namespace Tests\Feature;

use App\Filament\Pages\MyInventory;
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
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\EmployeeDistributionInventoryService;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyCustodyHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_returned_property_appears_in_history_tab_only(): void
    {
        [$employee, , $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createReturnRequest($employee, $custodian, $issuance, $unit);

        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        $service = app(EmployeeDistributionInventoryService::class);

        $onHand = $service->paginatedGroupedInventory(
            $employee,
            null,
            'distribution_date',
            'desc',
            10,
            'semi_expendable',
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
        );

        $history = $service->paginatedGroupedInventory(
            $employee,
            null,
            'distribution_date',
            'desc',
            10,
            'semi_expendable',
            EmployeeDistributionInventoryService::CUSTODY_TAB_HISTORY,
        );

        $this->assertSame(0, $onHand->total());
        $this->assertSame(1, $history->total());
        $this->assertSame($issuance->item_id, $history->first()->item_id);
    }

    public function test_property_custody_ledger_shows_issued_and_returned_rows(): void
    {
        [$employee, , $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createReturnRequest($employee, $custodian, $issuance, $unit);

        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        $ledger = app(EmployeeDistributionInventoryService::class)
            ->presentPropertyCustodyLedger($employee, $issuance->id);

        $types = collect($ledger['rows'])->pluck('type')->all();

        $this->assertContains('Issued', $types);
        $this->assertContains('Returned', $types);
        $this->assertSame('0', $ledger['header']['total_on_hand']);
    }

    public function test_my_inventory_history_tab_hides_property_action_cta(): void
    {
        [$employee, , $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createReturnRequest($employee, $custodian, $issuance, $unit);
        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        Livewire::actingAs($employee)
            ->test(MyInventory::class, ['category' => 'semi_expendable', 'custodyTab' => 'history'])
            ->assertSee('History')
            ->assertDontSee('Start property action');
    }

    public function test_employee_can_open_property_issuance_ledger_modal(): void
    {
        [$employee, , , $issuance] = $this->seedPropertyContext();

        Livewire::actingAs($employee)
            ->test(MyInventory::class, ['category' => 'semi_expendable'])
            ->call('openPropertyIssuanceLedger', $issuance->item_id)
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_property_issuance_ledger_uses_category_specific_control_labels(): void
    {
        [$employee, , , $semiIssuance] = $this->seedPropertyContext();

        $semiLedger = app(EmployeeDistributionInventoryService::class)
            ->presentPropertyIssuanceLedger($employee, $semiIssuance->item_id);

        $semiControl = $semiLedger['columns']['ics_par_no'];
        $this->assertSame('ICS No.', is_array($semiControl) ? $semiControl['label'] : $semiControl);

        $semiIdentifier = $semiLedger['columns']['property_number'];
        $this->assertSame('Inventory item no.', is_array($semiIdentifier) ? $semiIdentifier['label'] : $semiIdentifier);

        [$ppeEmployee, , , $ppeIssuance] = $this->seedPpePropertyContext();

        $ppeLedger = app(EmployeeDistributionInventoryService::class)
            ->presentPropertyIssuanceLedger($ppeEmployee, $ppeIssuance->item_id);

        $ppeControl = $ppeLedger['columns']['ics_par_no'];
        $this->assertSame('PAR No.', is_array($ppeControl) ? $ppeControl['label'] : $ppeControl);

        $ppeIdentifier = $ppeLedger['columns']['property_number'];
        $this->assertSame('Property No.', is_array($ppeIdentifier) ? $ppeIdentifier['label'] : $ppeIdentifier);
    }

    public function test_property_issuance_ledger_shows_ris_ics_and_returned_rows(): void
    {
        [$employee, , $custodian, $issuance, $unit] = $this->seedPropertyContext();

        $request = $this->createReturnRequest($employee, $custodian, $issuance, $unit);
        app(PropertyActionRequestWorkflowService::class)->execute($request, $custodian);

        $ledger = app(EmployeeDistributionInventoryService::class)
            ->presentPropertyIssuanceLedger($employee, $issuance->item_id, EmployeeDistributionInventoryService::CUSTODY_TAB_HISTORY);

        $this->assertArrayHasKey('ics_par_no', $ledger['columns']);
        $this->assertArrayNotHasKey('eul_status_label', $ledger['columns']);

        $risValues = collect($ledger['rows'])->pluck('ris_no');
        $this->assertTrue($risValues->contains('REQ-CUST-1'));

        $quantities = collect($ledger['rows'])->pluck('quantity')->map(fn ($qty) => (int) $qty)->all();
        $this->assertContains(-1, $quantities);
    }

    public function test_same_item_issuances_are_grouped_into_one_row(): void
    {
        [$employee, $uc, $custodian, $issuance, $unit] = $this->seedPropertyContext();
        $itemId = $issuance->item_id;
        $departmentId = $issuance->department_id;
        $officeId = $issuance->office_id;

        $secondAcquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-CUST-2',
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => 1,
            'unit_cost' => 2500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($secondAcquisition);
        $secondUnit = InventoryUnit::query()->where('acquisition_id', $secondAcquisition->id)->first();
        $this->assertNotNull($secondUnit);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-CUST-2',
            'office_id' => $officeId,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $secondIssuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-CUST-002',
            'item_id' => $itemId,
            'office_id' => $officeId,
            'department_id' => $departmentId,
            'quantity' => 1,
            'unit_cost' => 2500,
            'amount' => 2500,
            'issuance_date' => now()->subDay(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => $secondUnit->property_number,
            'estimated_useful_life' => '5 yrs',
        ]);

        $secondUnit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $secondIssuance->id,
        ]);

        $rows = app(EmployeeDistributionInventoryService::class)->paginatedGroupedInventory(
            $employee,
            null,
            'distribution_date',
            'desc',
            10,
            'semi_expendable',
        );

        $this->assertSame(1, $rows->total());
        $this->assertSame(2, (int) $rows->first()->total_quantity);
        $this->assertSame(2, (int) $rows->first()->distribution_count);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedger(
            $employee,
            $itemId,
        );

        $this->assertCount(2, $ledger['rows']);
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
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Desk Organizer',
        ]);

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
            'reference_code' => 'ACQ-CUST-1',
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
            'reference_code' => 'REQ-CUST-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-CUST-001',
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
            'estimated_useful_life' => '5 yrs',
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        return [$employee, $uc, $custodian, $issuance, $unit->fresh()];
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Issuance}
     */
    private function seedPpePropertyContext(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Air Conditioning Unit',
        ]);

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

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-PPE-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0039',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 50000,
            'amount' => 50000,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => '2026-0652',
        ]);

        return [$employee, $uc, $custodian, $issuance];
    }

    private function createReturnRequest(
        User $employee,
        User $custodian,
        Issuance $issuance,
        InventoryUnit $unit,
    ): PropertyActionRequest {
        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $custodian->id,
            'office_id' => $issuance->office_id,
            'status' => PropertyActionRequest::STATUS_APPROVED,
            'offline_approval_received' => true,
        ]);

        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'inventory_unit_id' => $unit->id,
            'sort_order' => 0,
        ]);

        return $request->fresh(['lines']);
    }
}
