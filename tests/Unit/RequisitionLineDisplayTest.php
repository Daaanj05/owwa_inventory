<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionCompileService;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionLineDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_identifier_table_header_is_asset_no(): void
    {
        $this->assertSame('Asset No.', OwwaReferenceLabels::assetIdentifierTableHeader());
    }

    public function test_identifier_resolves_through_compiled_parent_issuance(): void
    {
        $office = Office::factory()->create();
        $department = \App\Models\Department::query()->create([
            'office_id' => $office->id,
            'name' => 'General',
            'code' => 'GEN',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $item = Item::factory()->create(['item_code' => 'STK-LINE-01']);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-3001',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 1]],
            'Purpose',
            $office->id,
            $department->id,
        );

        Issuance::query()->create([
            'reference_code' => '2026-01-0501',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'requisition_id' => $consolidated->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
        ]);

        $line->refresh();

        $this->assertSame('STK-LINE-01', RequisitionLineDisplay::identifierValue($line));
    }

    public function test_identifier_uses_semi_expendable_inventory_item_number(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEMI-CODE-01',
            'semi_expendable_property_number' => 'HVE-2026-04-106-001-4A',
        ]);

        $line = $this->createLineForItem($item);

        $this->assertSame('Inventory item no.', RequisitionLineDisplay::identifierLabel($line));
        $this->assertSame('HVE-2026-04-106-001-4A', RequisitionLineDisplay::identifierValue($line));
    }

    public function test_identifier_uses_ppe_property_number(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-CODE-01',
            'ppe_property_number' => '2026-01-106-001-4A',
        ]);

        $line = $this->createLineForItem($item);

        $this->assertSame('Property No.', RequisitionLineDisplay::identifierLabel($line));
        $this->assertSame('2026-01-106-001-4A', RequisitionLineDisplay::identifierValue($line));
    }

    public function test_identifier_prefers_issuance_property_number_over_catalog(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'ppe_property_number' => '2026-01-106-001-4A',
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        Issuance::query()->create([
            'reference_code' => '2026-01-0601',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'property_number' => '2026-01-106-099-4A',
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
        ]);

        $this->assertSame('2026-01-106-099-4A', RequisitionLineDisplay::identifierValue($line->fresh()));
    }

    protected function createLineForItem(Item $item): RequisitionItem
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        return RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
    }
}
