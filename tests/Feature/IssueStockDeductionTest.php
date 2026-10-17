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
use App\Services\InventoryStockService;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionStockSnapshotService;
use App\Services\StockOpeningBalanceBatchService;
use App\Support\PpePropertyType;
use App\Support\UnitCostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueStockDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected Office $supply;

    protected Office $fieldOffice;

    protected Department $department;

    protected User $custodian;

    protected User $employee;

    protected int $requisitionSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supply = Office::factory()->create(['is_regional_supply' => true, 'code' => 'RO', 'name' => 'Regional Supply']);
        $this->fieldOffice = Office::factory()->create(['name' => 'Field Office']);
        $this->department = Department::query()->create([
            'office_id' => $this->fieldOffice->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $this->custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $this->supply->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $this->fieldOffice->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($this->custodian);
    }

    public function test_consumable_issue_spans_lots_oldest_first(): void
    {
        $item = $this->consumableItem();
        $this->confirmOpeningBalance($item, 3, 10);
        $this->insertAcquisition($item, 5, 12);

        $issued = $this->issue($item, 5);

        $this->assertSame(
            [['10.00', 3], ['12.00', 2]],
            $issued->map(fn (Issuance $row): array => [UnitCostKey::normalize((float) $row->unit_cost), (int) $row->quantity])->all(),
        );
        $this->assertSame(1, $issued->pluck('issuance_batch_id')->unique()->count());
        $this->assertTrue($issued->every(fn (Issuance $row): bool => $row->issued_from_office_id === $this->supply->id));

        $stock = $this->freshStock();
        $this->assertSame(3, $stock->getStock($item->id, $this->supply->id));
        $this->assertSame(0, $stock->getStockForUnitCost($item->id, $this->supply->id, 10));
        $this->assertSame(3, $stock->getStockForUnitCost($item->id, $this->supply->id, 12));
        $this->assertSame(5, (int) RequisitionItem::query()->where('item_id', $item->id)->value('quantity_issued'));
    }

    public function test_semi_expendable_opening_balance_drops_with_each_issue(): void
    {
        $item = $this->semiItem();
        $this->confirmOpeningBalance($item, 3, 500);

        $first = $this->issue($item, 2);
        $this->assertSame(1, $this->freshStock()->getStock($item->id, $this->supply->id));
        $this->assertSame(2, InventoryUnit::query()->whereIn('issuance_id', $first->modelKeys())->count());

        $this->issue($item, 1);
        $this->assertSame(0, $this->freshStock()->getStock($item->id, $this->supply->id));
        $this->assertSame(0, InventoryUnit::query()->where('item_id', $item->id)->where('status', InventoryUnit::STATUS_IN_STOCK)->count());
    }

    public function test_ppe_opening_balance_of_five_chairs_leaves_three_after_issuing_two(): void
    {
        $item = $this->ppeItem();
        $this->confirmOpeningBalance($item, 5, 55000);

        $issued = $this->issue($item, 2);

        $this->assertSame(3, $this->freshStock()->getStock($item->id, $this->supply->id));
        $this->assertSame(0, $this->freshStock()->getStock($item->id, $this->fieldOffice->id));
        $this->assertSame(3, InventoryUnit::query()->where('item_id', $item->id)->where('office_id', $this->supply->id)->where('status', InventoryUnit::STATUS_IN_STOCK)->count());
        $this->assertSame('55000.00', UnitCostKey::normalize((float) $issued->first()->unit_cost));
    }

    public function test_semi_issuable_quantity_is_capped_by_in_stock_units_at_supply(): void
    {
        $item = $this->semiItem();
        $this->confirmOpeningBalance($item, 3, 500);

        InventoryUnit::query()
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->limit(2)
            ->get()
            ->each(fn (InventoryUnit $unit) => $unit->update(['status' => InventoryUnit::STATUS_ISSUED]));
        $this->freshStock();

        $snapshot = app(RequisitionStockSnapshotService::class);
        $this->assertSame(3, $snapshot->regionalStockForItem($item->id));
        $this->assertSame(1, $snapshot->issuableStockForItem($item->id));

        $issued = $this->issue($item, 3);

        $this->assertSame(1, (int) $issued->sum('quantity'));
        $this->assertSame(2, $this->freshStock()->getStock($item->id, $this->supply->id));
    }

    public function test_issue_does_not_create_a_stock_row_for_the_receiving_office(): void
    {
        $item = $this->consumableItem();
        $this->confirmOpeningBalance($item, 4, 10);

        $this->issue($item, 2);

        $keys = array_keys($this->freshStock()->getActiveStockPositionKeys([$item->id]));

        $this->assertContains(UnitCostKey::positionKey($item->id, $this->supply->id, 10), $keys);
        $this->assertEmpty(array_filter(
            $keys,
            fn (string $key): bool => str_starts_with($key, "{$item->id}_{$this->fieldOffice->id}_"),
        ));
    }

    public function test_backfill_dry_run_changes_nothing_and_real_run_moves_and_recosts_legacy_issuances(): void
    {
        $item = $this->consumableItem();
        $this->confirmOpeningBalance($item, 5, 10);
        $this->insertAcquisition($item, 5, 12);
        $legacyId = $this->insertLegacyIssuance($item, 7, 12);

        $this->assertSame(10, $this->freshStock()->getStock($item->id, $this->supply->id));

        $this->artisan('inventory:backfill-issued-from-office', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Would update 1 issuance row(s); 1 extra row(s)')
            ->assertSuccessful();

        $legacy = Issuance::query()->findOrFail($legacyId);
        $this->assertNull($legacy->issued_from_office_id);
        $this->assertSame(7, (int) $legacy->quantity);
        $this->assertSame(1, Issuance::query()->where('item_id', $item->id)->count());
        $this->assertSame(10, $this->freshStock()->getStock($item->id, $this->supply->id));

        $this->artisan('inventory:backfill-issued-from-office')->assertSuccessful();

        $rows = Issuance::query()->where('item_id', $item->id)->orderBy('id')->get();
        $this->assertSame(
            [['10.00', 5], ['12.00', 2]],
            $rows->map(fn (Issuance $row): array => [UnitCostKey::normalize((float) $row->unit_cost), (int) $row->quantity])->all(),
        );
        $this->assertTrue($rows->every(fn (Issuance $row): bool => $row->issued_from_office_id === $this->supply->id));
        $this->assertSame($legacyId, $rows->first()->id);
        $this->assertSame(3, $this->freshStock()->getStock($item->id, $this->supply->id));

        $this->artisan('inventory:backfill-issued-from-office')
            ->expectsOutputToContain('No older requisition issuances')
            ->assertSuccessful();
    }

    public function test_backfill_keeps_semi_units_on_their_linked_cost(): void
    {
        $item = $this->semiItem();
        $this->confirmOpeningBalance($item, 3, 500);
        $legacyId = $this->insertLegacyIssuance($item, 1, 900);
        InventoryUnit::query()->where('item_id', $item->id)->orderBy('id')->first()
            ->update(['status' => InventoryUnit::STATUS_ISSUED, 'issuance_id' => $legacyId]);

        $this->artisan('inventory:backfill-issued-from-office')->assertSuccessful();

        $legacy = Issuance::query()->findOrFail($legacyId);
        $this->assertSame($this->supply->id, $legacy->issued_from_office_id);
        $this->assertSame('500.00', UnitCostKey::normalize((float) $legacy->unit_cost));
        $this->assertSame(2, $this->freshStock()->getStock($item->id, $this->supply->id));
    }

    protected function freshStock(): InventoryStockService
    {
        $stock = app(InventoryStockService::class);
        $stock->forgetMovementTotalsCache();

        return $stock;
    }

    protected function consumableItem(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(['name' => 'Consumables'], ['description' => 'Consumables']);

        return Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Bond Paper', 'unit' => 'ream']);
    }

    protected function semiItem(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(['name' => 'Semi-Expendable'], ['description' => 'Semi-expendable']);

        return Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Stapler']);
    }

    protected function ppeItem(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment'],
        );

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office Chair',
            'ppe_property_number' => 'PPE-2026-CHAIR',
            'ppe_type' => PpePropertyType::TechnicalScientificEquipment,
        ]);
    }

    protected function confirmOpeningBalance(Item $item, int $quantity, float $unitCost): void
    {
        $service = app(StockOpeningBalanceBatchService::class);
        $batch = $service->saveDraft(
            lines: [[
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
            memo: null,
            itemCategoryId: $item->item_category_id,
            recordedBy: $this->custodian,
        );

        $service->confirm($batch);
    }

    protected function insertAcquisition(Item $item, int $quantity, float $unitCost): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-'.$item->id.'-'.$unitCost,
            'item_id' => $item->id,
            'office_id' => $this->supply->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'acquisition_date' => now()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertLegacyIssuance(Item $item, int $quantity, float $unitCost): int
    {
        $requisition = $this->createRequisition($item, $quantity)[0];

        return DB::table('issuances')->insertGetId([
            'reference_code' => 'LEGACY-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $this->fieldOffice->id,
            'department_id' => $this->department->id,
            'requisition_id' => $requisition->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'amount' => $quantity * $unitCost,
            'issuance_date' => now()->toDateString(),
            'issued_to' => $this->employee->id,
            'issued_by' => $this->custodian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: Requisition, 1: RequisitionItem}
     */
    protected function createRequisition(Item $item, int $quantity): array
    {
        $this->requisitionSequence++;

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-90'.str_pad((string) $this->requisitionSequence, 2, '0', STR_PAD_LEFT),
            'office_id' => $this->fieldOffice->id,
            'department_id' => $this->department->id,
            'requested_by' => $this->employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
        ]);

        return [$requisition, $line];
    }

    /**
     * @return Collection<int, Issuance>
     */
    protected function issue(Item $item, int $quantity): Collection
    {
        [$requisition, $line] = $this->createRequisition($item, $quantity);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $this->custodian, [[
            'requisition_item_id' => $line->id,
            'quantity_to_issue' => $quantity,
        ]], now()->toDateString());

        return Issuance::query()->where('requisition_id', $requisition->id)->orderBy('id')->get();
    }
}
