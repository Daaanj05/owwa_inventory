<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Services\RequisitionFulfillmentService;
use App\Support\ItemPropertyClass;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SemiExpendableUsefulLifeTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_fulfillment_passes_estimated_useful_life_for_semi(): void
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
            'property_class' => ItemPropertyClass::Ict,
            'estimated_useful_life' => '7 yrs',
        ]);
        $custodian = User::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-EUL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0200',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => 'pending',
        ]);

        $line = \App\Models\RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $line->id,
                    'quantity_to_issue' => 1,
                ],
            ],
            now()->toDateString(),
        );

        $issuance = Issuance::query()->first();

        $this->assertNotNull($issuance);
        $this->assertSame('7 yrs', $issuance->estimated_useful_life);
        $this->assertNotNull($issuance->eul_expires_at);
    }

    public function test_semi_issuance_sets_eul_expires_at_from_useful_life(): void
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
            'property_class' => ItemPropertyClass::Ict,
            'estimated_useful_life' => '3 yrs',
        ]);
        $custodian = User::factory()->create();

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0210',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => 'pending',
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => '2024-01-01',
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'estimated_useful_life' => '3 yrs',
        ]);

        $this->assertSame('2027-01-01', $issuance->fresh()->eul_expires_at?->toDateString());
    }

    public function test_semi_issuance_without_eligible_useful_life_is_blocked(): void
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
            'property_class' => ItemPropertyClass::Ict,
            'estimated_useful_life' => '1 yr',
        ]);
        $custodian = User::factory()->create();

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0201',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'estimated_useful_life' => '1 yr',
        ]);
    }

    public function test_ics_export_column_h_uses_issuance_estimated_useful_life(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item([
            'item_code' => 'SEM-010',
            'name' => 'Laptop bag',
            'unit' => 'piece',
            'estimated_useful_life' => '3 yrs',
        ]);
        $issuance = new Issuance([
            'reference_code' => '2026-01-0044',
            'quantity' => 1,
            'unit_cost' => 3500,
            'amount' => 3500,
            'property_number' => 'SPLV-2024-ICT-106-01-001',
            'estimated_useful_life' => '5 yrs',
            'issuance_date' => now(),
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('item', $item);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance,
            'Semi-Expendable/Issuances & Acquisitions/Appendix 59 - ICS.xls',
        );

        $this->assertSame('5 yrs', $values['H12']);
    }

    public function test_resolve_for_item_falls_back_to_property_class_default(): void
    {
        $item = new Item([
            'property_class' => ItemPropertyClass::FurnituresFixtures,
            'estimated_useful_life' => null,
        ]);

        $this->assertSame('5 yrs', SemiExpendableUsefulLife::resolveForItem($item));
    }
}
