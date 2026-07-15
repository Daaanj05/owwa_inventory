<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OfficePropertyRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfficeStockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_stock_ledger_builds_running_balance(): void
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
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-LEDGER-1',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ISS-LEDGER-1',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 10,
            'issuance_date' => Carbon::parse('2026-01-01'),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
        ]);

        Distribution::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 3,
            'distribution_date' => Carbon::parse('2026-01-15'),
            'distributed_by' => $uc->id,
            'distributed_to' => $employee->id,
        ]);

        $ledger = app(OfficePropertyRegisterService::class)->presentOfficeStockLedger($uc, $item->id);

        $balances = collect($ledger['rows'])->pluck('balance')->map(fn ($balance): int => (int) $balance)->all();

        $this->assertContains(10, $balances);
        $this->assertContains(7, $balances);
        $this->assertSame('7', $ledger['header']['total_on_hand']);
        $this->assertFalse($ledger['show_property_units']);

        $distributionRow = collect($ledger['rows'])->firstWhere('type', 'Distributed');
        $this->assertNotNull($distributionRow);
        $this->assertSame($employee->name, $distributionRow['employee']);
        $this->assertArrayHasKey('employee', $ledger['columns']);
    }
}
