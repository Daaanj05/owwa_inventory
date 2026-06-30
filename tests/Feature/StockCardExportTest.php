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

    public function test_stock_ledger_presenter_builds_stock_card_export_url_with_form_and_office(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $present = app(StockLedgerViewService::class)->present($item, $office);

        $this->assertStringContainsString(route('owwa.export.item', $item), $present['exportUrl']);
        $this->assertStringContainsString('form=sc', $present['exportUrl']);
        $this->assertStringContainsString('office_id='.$office->id, $present['exportUrl']);
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
