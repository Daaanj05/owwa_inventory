<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PropertyReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyReturnTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_transfer_restores_inventory_unit_to_in_stock(): void
    {
        $supplyOffice = Office::factory()->create(['name' => 'Regional Supply']);
        $deptOffice = Office::factory()->create(['name' => 'Satellite Office']);
        $department = Department::query()->create([
            'office_id' => $deptOffice->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-RET-1',
            'item_id' => $item->id,
            'office_id' => $deptOffice->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0400',
            'office_id' => $deptOffice->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0401',
            'office_id' => $deptOffice->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'property_number' => 'SPLV-2026-ICT-01-01-099',
            'estimated_useful_life' => '5 yrs',
        ]);

        $acquisitionId = Acquisition::query()->where('item_id', $item->id)->value('id');

        $unit = InventoryUnit::query()->create([
            'acquisition_id' => $acquisitionId,
            'item_id' => $item->id,
            'office_id' => $deptOffice->id,
            'property_number' => 'SPLV-2026-ICT-01-01-099',
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        $transfer = Transfer::query()->create([
            'reference_code' => '2026-01-0402',
            'from_office_id' => $deptOffice->id,
            'to_office_id' => $supplyOffice->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'transfer_date' => now(),
            'transfer_type' => 'return',
            'property_number' => 'SPLV-2026-ICT-01-01-099',
            'recorded_by' => $custodian->id,
        ]);

        app(PropertyReturnService::class)->processReturnTransfer($transfer);

        $unit->refresh();

        $this->assertSame(InventoryUnit::STATUS_IN_STOCK, $unit->status);
        $this->assertNull($unit->issuance_id);
        $this->assertSame($supplyOffice->id, $unit->office_id);
    }

    public function test_non_return_transfer_does_not_flip_unit_status(): void
    {
        $fromOffice = Office::factory()->create();
        $toOffice = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-RET-2',
            'item_id' => $item->id,
            'office_id' => $fromOffice->id,
            'quantity' => 1,
            'unit_cost' => 50000,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $unit = InventoryUnit::query()->create([
            'acquisition_id' => $acquisition->id,
            'item_id' => $item->id,
            'office_id' => $fromOffice->id,
            'property_number' => 'PPE-2026-0001',
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => null,
        ]);

        $transfer = Transfer::query()->create([
            'reference_code' => '2026-01-0403',
            'from_office_id' => $fromOffice->id,
            'to_office_id' => $toOffice->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'transfer_date' => now(),
            'transfer_type' => 'relocate',
            'property_number' => 'PPE-2026-0001',
            'recorded_by' => $custodian->id,
        ]);

        app(PropertyReturnService::class)->processReturnTransfer($transfer);

        $this->assertSame(InventoryUnit::STATUS_ISSUED, $unit->fresh()->status);
    }
}
