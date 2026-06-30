<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\StockLedgerViewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockLedgerViewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumables_presenter_returns_stock_card_columns_and_export_form(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'days_to_consume' => 30,
        ]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertSame('Stock Card (Appendix 58)', $present['title']);
        $this->assertSame('sc', $present['exportForm']);
        $this->assertStringContainsString('form=sc', $present['exportUrl']);
        $this->assertArrayHasKey('days_to_consume', $present['columns']);
        $this->assertSame($item->name, $present['header']['item_name']);
    }

    public function test_ppe_presenter_returns_property_card_export_form(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertSame('Property Card (Appendix 69)', $present['title']);
        $this->assertSame('pc', $present['exportForm']);
        $this->assertArrayHasKey('office_officer', $present['columns']);
    }

    public function test_semi_presenter_returns_annex_a1_export_form(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertSame('Semi-Expendable Property Card (Annex A.1)', $present['title']);
        $this->assertSame('annex_a1', $present['exportForm']);
    }

    public function test_rows_include_running_balance_after_receipt_and_issue(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $this->createAcquisition($item->id, $office->id, 10);
        $this->createIssuance($item->id, $office->id, 3);

        $present = app(StockLedgerViewService::class)->present($item, $office);
        $balances = collect($present['rows'])->pluck('balance')->map(fn ($b) => (int) $b)->all();

        $this->assertContains(10, $balances);
        $this->assertContains(7, $balances);
    }

    public function test_assert_visible_in_stock_list_rejects_unknown_pairs(): void
    {
        $visible = collect([
            (object) ['item_id' => 1, 'office_id' => 2],
        ]);

        $this->expectException(AuthorizationException::class);

        app(StockLedgerViewService::class)->assertVisibleInStockList(99, 2, $visible);
    }

    public function test_assert_visible_in_stock_list_allows_listed_pairs(): void
    {
        $visible = collect([
            (object) ['item_id' => 5, 'office_id' => 8],
        ]);

        app(StockLedgerViewService::class)->assertVisibleInStockList(5, 8, $visible);

        $this->assertTrue(true);
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-LEDGER-'.$itemId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createIssuance(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-LEDGER-'.$itemId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'issuance_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
