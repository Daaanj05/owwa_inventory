<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use App\Services\RequisitionFulfillmentService;
use App\Services\StockOpeningBalanceBatchService;
use App\Support\PpePropertyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PpePerUnitPropertyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_ppe_opening_balance_mints_a_property_number_per_unit(): void
    {
        [$supply, $custodian, $item, $category] = $this->ppeSetup();

        $confirmed = $this->confirmOpeningBalance($custodian, $item, $category, 3);

        $numbers = InventoryUnit::query()
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->pluck('property_number');

        $this->assertCount(3, $numbers);
        $this->assertCount(3, $numbers->unique());
        $this->assertSame('PPE-2026-LAPTOP', $numbers->first());
        $this->assertSame('PPE-2026-LAPTOP', $item->fresh()->ppe_property_number);

        $labels = app(InventoryQrLabelService::class)->labelsForOpeningBalanceBatch($confirmed);
        $this->assertCount(3, $labels);
        $this->assertCount(3, $labels->pluck('property_number')->unique());
    }

    public function test_issuance_of_two_units_links_both_and_lists_every_unit_number(): void
    {
        [$supply, $custodian, $item, $category] = $this->ppeSetup();
        $this->confirmOpeningBalance($custodian, $item, $category, 3);

        $issuance = $this->issue($supply, $custodian, $item, 2);

        $linked = InventoryUnit::query()->where('issuance_id', $issuance->id)->orderBy('id')->get();
        $this->assertCount(2, $linked);
        $this->assertTrue($linked->every(fn (InventoryUnit $unit): bool => $unit->status === InventoryUnit::STATUS_ISSUED));

        $issuance->refresh();
        $this->assertSame($linked->pluck('property_number')->all(), $issuance->issuedPropertyNumbers());
        $this->assertSame($linked->pluck('property_number')->implode(', '), $issuance->issuedPropertyNumbersLabel());

        $html = view('owwa.par-print', ['issuance' => $issuance, 'title' => 'PAR'])->render();
        foreach ($linked as $unit) {
            $this->assertStringContainsString($unit->property_number, $html);
        }
    }

    public function test_renumber_command_only_changes_duplicate_in_stock_ppe_units(): void
    {
        [$supply, $custodian, $item] = $this->ppeSetup();

        $make = fn (string $status): InventoryUnit => InventoryUnit::query()->create([
            'property_number' => 'PPE-SHARED-001',
            'item_id' => $item->id,
            'office_id' => $supply->id,
            'unit_cost' => 75000,
            'status' => $status,
        ]);

        $oldest = $make(InventoryUnit::STATUS_IN_STOCK);
        $issued = $make(InventoryUnit::STATUS_ISSUED);
        $inStock = $make(InventoryUnit::STATUS_IN_STOCK);

        $this->artisan('inventory:renumber-ppe-units', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('PPE-SHARED-001', $inStock->fresh()->property_number);

        $this->artisan('inventory:renumber-ppe-units')->assertSuccessful();

        $this->assertSame('PPE-SHARED-001', $oldest->fresh()->property_number);
        $this->assertSame('PPE-SHARED-001', $issued->fresh()->property_number);
        $this->assertNotSame('PPE-SHARED-001', $inStock->fresh()->property_number);
    }

    /**
     * @return array{0: Office, 1: User, 2: Item, 3: ItemCategory}
     */
    protected function ppeSetup(): array
    {
        $supply = Office::factory()->create(['is_regional_supply' => true, 'code' => 'RO']);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment'],
        );
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Laptop',
            'ppe_property_number' => 'PPE-2026-LAPTOP',
            'ppe_type' => PpePropertyType::TechnicalScientificEquipment,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $supply->id,
        ]);

        $this->actingAs($custodian);

        return [$supply, $custodian, $item, $category];
    }

    protected function confirmOpeningBalance(User $custodian, Item $item, ItemCategory $category, int $quantity): \App\Models\StockOpeningBalanceBatch
    {
        $service = app(StockOpeningBalanceBatchService::class);
        $batch = $service->saveDraft(
            lines: [[
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_cost' => 75000,
            ]],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $custodian,
        );

        return $service->confirm($batch);
    }

    protected function issue(Office $supply, User $custodian, Item $item, int $quantity): Issuance
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-PPE-'.$quantity,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [[
            'requisition_item_id' => $line->id,
            'quantity_to_issue' => $quantity,
        ]], now()->toDateString());

        return Issuance::query()->where('requisition_id', $requisition->id)->firstOrFail();
    }
}
