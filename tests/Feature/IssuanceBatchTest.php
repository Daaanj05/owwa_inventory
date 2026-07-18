<?php

namespace Tests\Feature;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\Issuance;
use App\Models\IssuanceBatch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Services\RequisitionFulfillmentService;
use App\Services\StockLedgerViewService;
use App\Support\OwwaReferenceLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssuanceBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_lines_creates_one_batch_with_shared_control_number_for_same_category(): void
    {
        [$requisition, $custodian, $lineA, $lineB] = $this->seedConsumableRequisitionWithTwoLines(quantityA: 2, quantityB: 3);

        $result = app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            ['requisition_item_id' => $lineA->id, 'quantity_to_issue' => 2],
            ['requisition_item_id' => $lineB->id, 'quantity_to_issue' => 3],
        ], now()->toDateString());

        $this->assertSame(2, $result['created']);

        $issuances = Issuance::query()->where('requisition_id', $requisition->id)->orderBy('id')->get();
        $this->assertCount(2, $issuances);
        $this->assertNotNull($issuances->first()->issuance_batch_id);
        $this->assertSame($issuances->first()->issuance_batch_id, $issuances->last()->issuance_batch_id);

        $control = $issuances->first()->controlNumber();
        $this->assertNotNull($control);
        $this->assertSame($control, $issuances->last()->controlNumber());
        $this->assertSame(1, IssuanceBatch::query()->count());
    }

    public function test_issuances_resource_lists_one_transaction_row_per_batch(): void
    {
        [$requisition, $custodian, $lineA, $lineB] = $this->seedConsumableRequisitionWithTwoLines(quantityA: 2, quantityB: 3);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            ['requisition_item_id' => $lineA->id, 'quantity_to_issue' => 2],
            ['requisition_item_id' => $lineB->id, 'quantity_to_issue' => 3],
        ], now()->toDateString());

        $categoryId = $lineA->item()->value('item_category_id');
        session(['active_item_category_id' => $categoryId]);

        $resourceRows = IssuanceResource::getEloquentQuery()->get();

        $this->assertCount(1, $resourceRows);
        $this->assertCount(2, $resourceRows->first()->batchLines());
        $this->assertSame(5, (int) $resourceRows->first()->batchLines()->sum('quantity'));
    }

    public function test_mixed_category_requisition_creates_separate_batches(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);
        $consumableItem = Item::factory()->create(['item_category_id' => $consumables->id]);
        $ppeItem = Item::factory()->create(['item_category_id' => $ppe->id]);

        foreach ([[$consumableItem, 50], [$ppeItem, 5]] as [$item, $qty]) {
            DB::table('acquisitions')->insert([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => $qty,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0100',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $consumableLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $consumableItem->id,
            'quantity' => 2,
        ]);
        $ppeLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $ppeItem->id,
            'quantity' => 1,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            ['requisition_item_id' => $consumableLine->id, 'quantity_to_issue' => 2],
            ['requisition_item_id' => $ppeLine->id, 'quantity_to_issue' => 1],
        ], now()->toDateString());

        $this->assertSame(2, IssuanceBatch::query()->count());
        $this->assertSame(2, Issuance::query()->where('requisition_id', $requisition->id)->count());
        $this->assertNotSame(
            Issuance::query()->where('item_id', $consumableItem->id)->value('issuance_batch_id'),
            Issuance::query()->where('item_id', $ppeItem->id)->value('issuance_batch_id'),
        );
    }

    public function test_par_export_uses_batch_control_number_and_multiple_detail_rows(): void
    {
        [$requisition, $custodian, $lineA, $lineB] = $this->seedPpeRequisitionWithTwoLines();

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [
            ['requisition_item_id' => $lineA->id, 'quantity_to_issue' => 1],
            ['requisition_item_id' => $lineB->id, 'quantity_to_issue' => 1],
        ], now()->toDateString());

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->orderBy('id')->first();
        $this->assertNotNull($issuance);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance,
            'ppe/Issuances/Appendix 71 - PAR.xls',
        );

        $control = $issuance->controlNumber();
        $this->assertNotNull($control);
        $this->assertStringContainsString((string) $control, (string) ($values['E7'] ?? ''));
        $this->assertArrayHasKey('A11', $values);
        $this->assertArrayHasKey('A12', $values);
    }

    public function test_stock_ledger_header_uses_inventory_item_no_label_for_semi(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'semi_expendable_property_number' => 'SPLV-2026-OE-106-OWWA-IVA-001',
        ]);

        $presented = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertSame(
            OwwaReferenceLabels::INVENTORY_ITEM_NO,
            $presented['header']['asset_identifier_label'] ?? null,
        );
    }

    /**
     * @return array{0: Requisition, 1: User, 2: RequisitionItem, 3: RequisitionItem}
     */
    protected function seedConsumableRequisitionWithTwoLines(int $quantityA, int $quantityB): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $itemA = Item::factory()->create(['item_category_id' => $category->id]);
        $itemB = Item::factory()->create(['item_category_id' => $category->id]);

        foreach ([$itemA, $itemB] as $item) {
            DB::table('acquisitions')->insert([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 100,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0200',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $lineA = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemA->id,
            'quantity' => $quantityA,
        ]);
        $lineB = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemB->id,
            'quantity' => $quantityB,
        ]);

        return [$requisition, $custodian, $lineA, $lineB];
    }

    /**
     * @return array{0: Requisition, 1: User, 2: RequisitionItem, 3: RequisitionItem}
     */
    protected function seedPpeRequisitionWithTwoLines(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $itemA = Item::factory()->create(['item_category_id' => $category->id]);
        $itemB = Item::factory()->create(['item_category_id' => $category->id]);

        foreach ([$itemA, $itemB] as $item) {
            DB::table('acquisitions')->insert([
                'reference_code' => 'ACQ-PPE-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 10,
                'unit_cost' => 1000,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0300',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $lineA = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemA->id,
            'quantity' => 1,
        ]);
        $lineB = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $itemB->id,
            'quantity' => 1,
        ]);

        return [$requisition, $custodian, $lineA, $lineB];
    }
}
