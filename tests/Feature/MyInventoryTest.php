<?php

namespace Tests\Feature;

use App\Filament\Pages\MyInventory;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_my_inventory_uses_title_case_labels(): void
    {
        $this->assertSame('My Inventory', MyInventory::getNavigationLabel());
    }

    public function test_distribution_ledger_is_paginated(): void
    {
        $distributions = [];
        for ($i = 1; $i <= 12; $i++) {
            $distributions[] = ['quantity' => 1, 'date' => sprintf('2026-01-%02d', min($i, 28))];
        }

        [$employee, $item] = $this->seedEmployeeDistributions($distributions);

        $service = app(EmployeeDistributionInventoryService::class);
        $page1 = $service->presentLedgerPaginated($employee, $item->id, 1, 10);
        $page2 = $service->presentLedgerPaginated($employee, $item->id, 2, 10);

        $this->assertCount(10, $page1['rows']);
        $this->assertCount(2, $page2['rows']);
        $this->assertSame(12, $page1['paginator']->total());
        $this->assertSame(2, $page1['paginator']->lastPage());
    }

    public function test_distribution_ledger_modal_resets_to_first_page(): void
    {
        $distributions = [];
        for ($i = 1; $i <= 12; $i++) {
            $distributions[] = ['quantity' => 1, 'date' => sprintf('2026-01-%02d', min($i, 28))];
        }

        [$employee, $item] = $this->seedEmployeeDistributions($distributions);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->set('ledgerPage', 2)
            ->call('openDistributionLedger', $item->id)
            ->assertSet('ledgerPage', 1);
    }

    public function test_grouped_inventory_merges_same_item_rows(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->assertSee('Test Item')
            ->assertSee('8');

        $rows = app(EmployeeDistributionInventoryService::class)
            ->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc');

        $this->assertSame(1, $rows->total());
        $this->assertSame(8, (int) $rows->first()->total_quantity);
        $this->assertSame(2, (int) $rows->first()->distribution_count);
    }

    public function test_summary_kpis_match_merged_totals(): void
    {
        [$employee] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        $summary = app(EmployeeDistributionInventoryService::class)->summaryFor($employee);

        $this->assertSame(1, $summary['totalItems']);
        $this->assertSame(8, $summary['totalQuantity']);
        $this->assertSame(8, $summary['totalQuantityThisYear']);
    }

    public function test_employee_can_open_distribution_ledger_modal(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->call('openDistributionLedger', $item->id)
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_ledger_includes_running_balances(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentLedger($employee, $item->id);

        $balances = collect($ledger['rows'])->pluck('balance')->map(fn ($b) => (int) $b)->all();

        $this->assertContains(5, $balances);
        $this->assertContains(8, $balances);
        $this->assertSame('8', $ledger['header']['total_on_hand']);
    }

    public function test_open_distribution_ledger_rejects_item_not_distributed_to_employee(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        $otherItem = Item::factory()->create([
            'item_category_id' => $item->item_category_id,
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->call('openDistributionLedger', $otherItem->id)
            ->assertStatus(403);
    }

    public function test_ledger_item_url_param_auto_mounts_modal(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class, ['ledgerItem' => $item->id])
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_category_dropdown_filters_inventory_and_kpis(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::query()->firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Consumables'],
        );
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );

        $consumableItem = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Bond Paper',
        ]);
        $ppeItem = Item::factory()->create([
            'item_category_id' => $ppe->id,
            'name' => 'Desktop Computer',
        ]);

        /** @var User $employee */
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        /** @var User $distributor */
        $distributor = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        Distribution::factory()->create([
            'office_id' => $office->id,
            'item_id' => $consumableItem->id,
            'distributed_to' => $employee->id,
            'distributed_by' => $distributor->id,
            'quantity' => 5,
            'distribution_date' => '2026-03-07',
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-PPE-1',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'PAR-PPE-1',
            'office_id' => $office->id,
            'item_id' => $ppeItem->id,
            'quantity' => 1,
            'issuance_date' => '2026-03-08',
            'issued_by' => $distributor->id,
            'issued_to' => $employee->id,
            'property_number' => 'PPE-EMP-001',
        ]);

        $service = app(EmployeeDistributionInventoryService::class);

        $this->assertSame(
            ['consumables', 'semi_expendable', 'ppe'],
            array_keys(EmployeeDistributionInventoryService::categoryOptions()),
        );

        $consumableRows = $service->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc', 10, 'consumables');
        $ppeRows = $service->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc', 10, 'ppe');

        $this->assertSame(1, $consumableRows->total());
        $this->assertSame('Bond Paper', $consumableRows->first()->item_name);
        $this->assertSame(1, $ppeRows->total());
        $this->assertSame('Desktop Computer', $ppeRows->first()->item?->name);

        $ppeSummary = $service->summaryFor($employee, 'ppe');
        $this->assertSame(1, $ppeSummary['totalItems']);
        $this->assertSame(1, $ppeSummary['totalQuantity']);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->assertSet('category', 'consumables')
            ->assertSee('Bond Paper')
            ->assertDontSee('Desktop Computer')
            ->set('category', 'ppe')
            ->assertSet('category', 'ppe')
            ->assertSee('Desktop Computer')
            ->assertDontSee('Bond Paper');
    }

    public function test_semi_inventory_table_shows_distribution_columns(): void
    {
        [$employee] = $this->seedSemiPropertyIssuance();

        $rows = app(EmployeeDistributionInventoryService::class)->paginatedGroupedInventory(
            $employee,
            null,
            'distribution_date',
            'desc',
            10,
            'semi_expendable',
        );

        $this->assertSame(1, $rows->total());
        $this->assertSame(1, (int) $rows->first()->distribution_count);
        $this->assertNotNull($rows->first()->last_distribution_date);

        Livewire::actingAs($employee)
            ->test(MyInventory::class, ['category' => 'semi_expendable'])
            ->assertSee('Total qty')
            ->assertSee('Last received')
            ->assertSee('Distributions');
    }

    public function test_consumable_ledger_uses_ris_number_column(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $distributor = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0100',
            'office_id' => $office->id,
            'requested_by' => $distributor->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Distribution::factory()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'requisition_id' => $requisition->id,
            'distributed_to' => $employee->id,
            'distributed_by' => $distributor->id,
            'quantity' => 2,
            'distribution_date' => now(),
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentLedger($employee, $item->id);

        $referenceColumn = $ledger['columns']['reference'];
        $this->assertSame('RIS No.', is_array($referenceColumn) ? $referenceColumn['label'] : $referenceColumn);
        $this->assertSame('2026-07-0100', collect($ledger['rows'])->first()['reference']);
        $this->assertSame($item->item_code, $ledger['header']['stock_no']);
    }

    public function test_consumable_ledger_shows_unit_consolidator_as_distributed_by(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'name' => 'Unit Consolidator One']);
        User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'name' => 'Supply Custodian']);

        Distribution::factory()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'quantity' => 1,
            'distribution_date' => now(),
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentLedger($employee, $item->id);

        $this->assertSame('Unit Consolidator One', collect($ledger['rows'])->first()['distributed_by']);
    }

    public function test_property_ledger_shows_unit_consolidator_not_supply_custodian(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'office_id' => $office->id]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'name' => 'UC Maria', 'office_id' => $office->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'name' => 'SC Pedro', 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0300',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'PAR-2026-001',
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $employee->id,
            'property_number' => '2026-0001',
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedger($employee, $item->id);

        $this->assertSame('UC Maria', collect($ledger['rows'])->first()['distributed_by']);
        $this->assertNotSame('SC Pedro', collect($ledger['rows'])->first()['distributed_by']);
    }

    public function test_semi_property_ledger_shows_endorsing_uc_from_compiled_request(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Chair']);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'office_id' => $office->id]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'name' => 'UC Compiled', 'office_id' => $office->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'name' => 'SC Regional', 'office_id' => $office->id]);

        $employeeRequisition = Requisition::query()->create([
            'reference_code' => 'EMP-REQ-1',
            'transaction_number' => 'TXN-001',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'endorsed_by' => $uc->id,
            'endorsed_at' => now(),
        ]);

        $ucRis = Requisition::query()->create([
            'reference_code' => '2026-07-0400',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $employeeRequisition->update(['compiled_into_requisition_id' => $ucRis->id]);

        Issuance::query()->create([
            'requisition_id' => $ucRis->id,
            'reference_code' => 'ICS-2026-001',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $employee->id,
            'property_number' => 'SPLV-2026-OE-106-01-001',
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedger($employee, $item->id);

        $this->assertSame('UC Compiled', collect($ledger['rows'])->first()['distributed_by']);
    }

    public function test_property_ledger_shows_dash_when_only_supply_custodian_on_chain(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'office_id' => $office->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'name' => 'SC Only', 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0500',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'PAR-2026-002',
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $employee->id,
            'property_number' => '2026-0002',
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedger($employee, $item->id);

        $this->assertSame('—', collect($ledger['rows'])->first()['distributed_by']);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Issuance}
     */
    protected function seedSemiPropertyIssuance(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Chair']);

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'office_id' => $office->id]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0200',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-07-0201',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
            'property_number' => 'SEMI-001',
        ]);

        return [$employee, $uc, $custodian, $issuance];
    }

    /**
     * @param  array<int, array{quantity: int, date: string}>  $distributions
     * @return array{0: User, 1: Item}
     */
    protected function seedEmployeeDistributions(array $distributions): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Test Item',
        ]);

        /** @var User $employee */
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        /** @var User $distributor */
        $distributor = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        foreach ($distributions as $distribution) {
            Distribution::factory()->create([
                'office_id' => $office->id,
                'item_id' => $item->id,
                'distributed_to' => $employee->id,
                'distributed_by' => $distributor->id,
                'quantity' => $distribution['quantity'],
                'distribution_date' => $distribution['date'],
            ]);
        }

        return [$employee, $item];
    }
}
