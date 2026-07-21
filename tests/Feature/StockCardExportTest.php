<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\StockLedgerViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCardExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_ledger_presenter_builds_bulk_stock_card_export_url_with_pair(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertStringContainsString(route('owwa.export.bulk.stock-cards'), $present['exportUrl']);
        $this->assertStringContainsString('pairs=', $present['exportUrl']);
        $this->assertStringContainsString('category='.$category->id, $present['exportUrl']);
        $this->assertStringContainsString('pairs=', $present['exportPdfUrl']);
        $this->assertStringContainsString('format=pdf', $present['exportPdfUrl']);
    }

    public function test_semi_ledger_presenter_builds_bulk_annex_a1_export_urls(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office, 250.0);

        $this->assertSame('annex_a1', $present['exportForm']);
        $this->assertStringContainsString(route('owwa.export.bulk.stock-cards'), $present['exportUrl']);
        $this->assertStringContainsString('pairs=', $present['exportUrl']);
        $this->assertStringNotContainsString('/reports/owwa/item/', $present['exportUrl']);
        $this->assertStringContainsString('format=pdf', $present['exportPdfUrl']);
        $this->assertStringContainsString('pairs=', $present['exportPdfUrl']);
    }

    public function test_item_stock_card_route_returns_spreadsheet(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $response = $this->actingAs($custodian)->get(
            route('owwa.export.item', $item).'?form=sc&office_id='.$office->id,
        );

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }
}
