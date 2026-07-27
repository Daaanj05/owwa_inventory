<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Distribution;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\EmployeeDistributionExportService;
use App\Services\EmployeeDistributionInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeDistributionExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_employee_transaction_number_when_distribution_links_to_uc_ris(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Alcohol 70% 500ml',
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
            'name' => 'Ana Reyes',
        ]);

        $ucRis = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-2026-0003',
            'purpose' => 'April replenishment',
        ]);

        $employeeRequest = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-0001',
            'compiled_into_requisition_id' => $ucRis->id,
            'purpose' => 'Desk supplies',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequest->id,
            'item_id' => $item->id,
            'quantity' => 4,
        ]);

        Distribution::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requisition_id' => $ucRis->id,
            'item_id' => $item->id,
            'quantity' => 4,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => '2026-04-05',
            'remarks' => 'April replenishment',
        ]);

        $rows = $this->exportDetailRows(
            $employee,
            EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES,
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
            $item->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('REQ-2026-0003', $rows[0]['ris_no']);
        $this->assertSame('2026-0001', $rows[0]['requisition_txn']);

        $sheet = $this->exportWorksheet(
            $employee,
            EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES,
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
            $item->id,
        );
        $this->assertSame('Transaction No.', (string) $sheet->getCell('I6')->getValue());
        $this->assertSame('2026-0001', (string) $sheet->getCell('I7')->getValue());
    }

    public function test_export_uses_transaction_number_from_direct_employee_requisition_link(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Ballpoint Pen Blue',
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

        $employeeRequest = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-0042',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequest->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        Distribution::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requisition_id' => $employeeRequest->id,
            'requisition_item_id' => $line->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'distributed_to' => $employee->id,
            'distributed_by' => $uc->id,
            'distribution_date' => '2026-04-05',
        ]);

        $rows = $this->exportDetailRows(
            $employee,
            EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES,
            EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND,
            $item->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2026-0042', $rows[0]['requisition_txn']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function exportDetailRows(User $employee, string $category, string $custodyTab, int $itemId): array
    {
        $service = app(EmployeeDistributionExportService::class);
        $method = new ReflectionMethod($service, 'detailRows');

        return $method->invoke($service, $employee, $category, $custodyTab, null, null, $itemId)->values()->all();
    }

    protected function exportWorksheet(User $employee, string $category, string $custodyTab, int $itemId): Worksheet
    {
        $response = app(EmployeeDistributionExportService::class)->download(
            $employee,
            $category,
            $custodyTab,
            null,
            null,
            $itemId,
        );

        ob_start();
        $response->sendContent();
        $binary = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'custody-export-').'.xlsx';
        file_put_contents($path, $binary);

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }
}
