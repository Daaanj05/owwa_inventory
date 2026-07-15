<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\OwwaItemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaRegistryReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_registry_rows_populates_returned_qty_from_return_transfer(): void
    {
        $office = Office::factory()->create(['name' => 'Satellite Office']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $propertyNumber = 'SPLV-2026-TEST-001';

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-REG-001',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-REG-001',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 1500,
            'issuance_date' => now()->subMonth(),
            'issued_by' => $custodian->id,
            'property_number' => $propertyNumber,
        ]);

        Transfer::query()->create([
            'reference_code' => 'PTR-REG-001',
            'from_office_id' => $office->id,
            'to_office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'transfer_date' => now(),
            'transfer_type' => 'return',
            'property_number' => $propertyNumber,
            'recorded_by' => $custodian->id,
        ]);

        $rows = app(OwwaItemReportService::class)->buildRegistryRows($item, $office->id);

        $matched = collect($rows)->first(
            fn (array $row): bool => ($row['property_number'] ?? null) === $propertyNumber
                && ($row['returned_qty'] ?? null) === 1,
        );

        $this->assertNotNull($matched);
        $this->assertSame('Satellite Office', $matched['returned_office']);
    }
}
