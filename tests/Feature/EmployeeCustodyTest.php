<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeCustody;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\EmployeeDistributionInventoryService;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeCustodyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unit_consolidator_can_access_employee_custody_page(): void
    {
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $this->assertInstanceOf(User::class, $uc);

        $this->actingAs($uc)
            ->get(EmployeeCustody::getUrl())
            ->assertOk()
            ->assertSee('Employee Custody')
            ->assertDontSee('Distinct items')
            ->assertDontSee('Total on hand');
    }

    public function test_employee_custody_shows_export_all_item_and_period_filters_below_employee(): void
    {
        [$uc, $employee] = $this->seedEmployeeDistributions();

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, [
                'employee' => $employee->id,
            ])
            ->assertSee('Export All Item')
            ->assertSeeHtml('id="custody-from-date"')
            ->assertSeeHtml('id="custody-to-date"')
            ->assertDontSee('Download all for period');
    }

    public function test_distribution_history_modal_has_download_this_item_footer_only(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/EmployeeCustody.php'));

        $this->assertStringContainsString("->label('Download this item')", $source);
        $this->assertStringContainsString('extraModalFooterActions', $source);
        $this->assertStringContainsString('owwa-employee-custody-ledger-modal', $source);
        $this->assertStringContainsString('mountedLedgerActionArguments', $source);
        $this->assertStringNotContainsString('exportAllUrl', $source);

        $modal = file_get_contents(resource_path('views/filament/pages/partials/employee-distribution-ledger-modal.blade.php'));
        $this->assertStringNotContainsString('Download all for period', $modal);
        $this->assertStringNotContainsString('Download this item', $modal);
    }

    public function test_employee_custody_uses_inventory_page_header_class(): void
    {
        $page = new EmployeeCustody;
        $this->assertContains('owwa-inv-category-page', $page->getPageClasses());
    }

    public function test_employee_cannot_access_employee_custody(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        Livewire::actingAs($employee)
            ->test(EmployeeCustody::class)
            ->assertForbidden();
    }

    public function test_supply_custodian_cannot_access_employee_custody(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Livewire::actingAs($custodian)
            ->test(EmployeeCustody::class)
            ->assertForbidden();
    }

    public function test_uc_sees_only_employees_in_office_scope(): void
    {
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $inScope = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'In Scope Employee',
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Other Office Employee',
            'office_id' => $otherOffice->id,
        ]);

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class)
            ->assertSee('In Scope Employee')
            ->assertDontSee('Other Office Employee')
            ->set('employee', $inScope->id)
            ->assertSet('employee', $inScope->id);
    }

    public function test_uc_viewing_employee_shows_grouped_inventory_totals(): void
    {
        [$uc, $employee, $item] = $this->seedEmployeeDistributions();

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, ['employee' => $employee->id])
            ->assertSee('Test Item')
            ->assertSee('8');
    }

    public function test_uc_can_filter_employee_custody_by_distribution_period(): void
    {
        [$uc, $employee, $item] = $this->seedEmployeeDistributions();

        /** @var Issuance $oldIssuance */
        $oldIssuance = Issuance::query()
            ->where('issued_to', $employee->id)
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->firstOrFail();

        /** @var Issuance $newIssuance */
        $newIssuance = Issuance::query()
            ->where('issued_to', $employee->id)
            ->where('item_id', $item->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $oldIssuance->update(['issuance_date' => now()->subMonth()]);
        $newIssuance->update(['issuance_date' => now()]);

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, [
                'employee' => $employee->id,
                'fromDate' => now()->startOfMonth()->toDateString(),
                'toDate' => now()->endOfMonth()->toDateString(),
            ])
            ->assertSee('Test Item')
            ->assertSeeHtml('<td class="owwa-num owwa-cell-primary">3</td>')
            ->assertDontSeeHtml('<td class="owwa-num owwa-cell-primary">8</td>');
    }

    public function test_uc_cannot_open_ledger_for_employee_outside_scope(): void
    {
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $otherEmployee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $otherOffice->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        Distribution::factory()->create([
            'office_id' => $otherOffice->id,
            'item_id' => $item->id,
            'distributed_to' => $otherEmployee->id,
            'distributed_by' => $uc->id,
            'quantity' => 3,
            'distribution_date' => now(),
        ]);

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, ['employee' => $otherEmployee->id])
            ->call('openDistributionLedger', $item->id)
            ->assertStatus(403);
    }

    public function test_uc_can_open_read_only_distribution_ledger_modal(): void
    {
        [$uc, $employee, $item] = $this->seedEmployeeDistributions();

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, ['employee' => $employee->id])
            ->call('openDistributionLedger', $item->id)
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_employee_custody_page_does_not_show_property_action_ctas(): void
    {
        $source = file_get_contents(resource_path('views/filament/pages/employee-custody.blade.php'));

        $this->assertStringNotContainsString('Start property action', $source);
        $this->assertStringNotContainsString('propertyActionUrl', $source);
    }

    public function test_uc_employee_custody_history_tab_shows_returned_property(): void
    {
        [$uc, $employee, $issuance] = $this->seedEmployeePropertyIssuance();

        $request = \App\Models\PropertyActionRequest::query()->create([
            'action_type' => \App\Models\PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $issuance->office_id,
            'status' => \App\Models\PropertyActionRequest::STATUS_APPROVED,
        ]);

        $unit = InventoryUnit::query()->where('issuance_id', $issuance->id)->first();
        $this->assertNotNull($unit);

        \App\Models\PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'inventory_unit_id' => $unit->id,
            'sort_order' => 0,
        ]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request->fresh(['lines']),
            User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]),
        );

        $service = app(EmployeeDistributionInventoryService::class);

        $onHand = $service->paginatedPropertyIssuances(
            $employee,
            null,
            'distribution_date',
            'desc',
            10,
            'semi_expendable',
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
        );

        $history = $service->paginatedPropertyIssuances(
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

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, ['employee' => $employee->id, 'category' => 'semi_expendable', 'custodyTab' => 'history'])
            ->assertSee('History')
            ->assertSee('Desk Organizer');
    }

    public function test_uc_on_hand_property_item_opens_units_modal(): void
    {
        [$uc, $employee, $issuance] = $this->seedEmployeePropertyIssuance();

        Livewire::actingAs($uc)
            ->test(EmployeeCustody::class, [
                'employee' => $employee->id,
                'category' => 'semi_expendable',
                'custodyTab' => EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
            ])
            ->call('openPropertyIssuanceLedger', (int) $issuance->item_id)
            ->assertActionMounted('viewPropertyItemUnits');
    }

    public function test_uc_property_units_include_request_action_for_on_hand_items(): void
    {
        [$uc, $employee, $issuance] = $this->seedEmployeePropertyIssuance();

        $ledger = app(EmployeeDistributionInventoryService::class)->presentPropertyItemUnits(
            $employee,
            (int) $issuance->item_id,
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
            $uc,
        );

        $this->assertTrue($ledger['rows'][0]['show_property_action'] ?? false);
        $this->assertNotNull($ledger['rows'][0]['property_action_url'] ?? null);
    }

    /**
     * @return array{0: User, 1: User, 2: Issuance}
     */
    protected function seedEmployeePropertyIssuance(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Desk Organizer',
        ]);

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-UC-CUST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 3000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->first();
        $this->assertNotNull($unit);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-UC-CUST-1',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-UC-CUST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'unit_cost' => 3000,
            'amount' => 3000,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => $unit->property_number,
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        return [$uc, $employee, $issuance];
    }

    /**
     * @return array{0: User, 1: User, 2: Item}
     */
    protected function seedEmployeeDistributions(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Test Item',
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-CUST-1',
        ]);

        foreach ([5, 3] as $quantity) {
            Issuance::query()->create([
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requisition_id' => $requisition->id,
                'item_id' => $item->id,
                'quantity' => $quantity,
                'issued_to' => $employee->id,
                'issued_by' => $uc->id,
                'issuance_date' => now(),
            ]);
        }

        return [$uc, $employee, $item];
    }
}
