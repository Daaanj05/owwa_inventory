<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Jobs\GenerateStockCardExportJob;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\StockCardFastPdfExportService;
use App\Services\StockLevelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class StockCardFastExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_sync_route_returns_pdf_for_selected_pairs(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.stock-cards-fast', [
                'category' => $category->id,
                'pairs' => $item->id.':'.$office->id.':0',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $html = view('reports.stock-card-fast', [
            'cards' => [[
                'item_name' => $item->name,
                'item_code' => $item->item_code,
                'description' => '',
                'unit' => $item->unit,
                'reorder_level' => $item->reorder_level,
                'days_to_consume' => '',
                'office_name' => $office->name,
                'transactions' => [],
            ]],
            'entityName' => 'OWWA-4A',
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => '2026-10-02 18:54',
        ])->render();

        $this->assertStringContainsString('Generated on 2026-10-02 18:54', $html);
        $ledgerPos = strpos($html, 'txn-table');
        $generatedPos = strpos($html, 'Generated on 2026-10-02 18:54');
        $brandPos = strpos($html, 'brand-header-wrap');
        $this->assertNotFalse($ledgerPos);
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($brandPos);
        $this->assertGreaterThan($brandPos, $generatedPos);
        $this->assertGreaterThan($ledgerPos, $generatedPos);
        $this->assertStringContainsString('bagong-pilipinas-form-logo', $html);
        $this->assertStringContainsString('owwa-form-logo', $html);
        $this->assertStringContainsString('brand-header-wrap', $html);
        $this->assertStringContainsString('brand-header', $html);
        $this->assertStringContainsString('brand-logo--left', $html);
        $this->assertStringContainsString('brand-logo--right', $html);
        $this->assertStringNotContainsString('data:image/', $html);
    }

    public function test_fast_sync_route_packs_zip_when_over_batch_size_without_pairs(): void
    {
        [$custodian, $category, $office] = $this->seedConsumableStock();

        $items = Item::factory()
            ->count(StockLevelExportService::BATCH_SIZE + 5)
            ->create(['item_category_id' => $category->id]);

        foreach ($items as $item) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-MANY'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.stock-cards-fast', [
                'category' => $category->id,
                'fast_pack' => 1,
                'export_max' => StockLevelExportService::FAST_MAX,
            ]))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('content-type', 'application/zip');
    }

    public function test_fast_sync_preview_returns_html_lookalike(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.stock-cards-fast', [
                'category' => $category->id,
                'pairs' => $item->id.':'.$office->id.':0',
                'preview' => 1,
            ]))
            ->assertOk()
            ->assertSee('STOCK CARD', false)
            ->assertSee('Republic of the Philippines', false)
            ->assertSee('OVERSEAS WORKERS WELFARE ADMINISTRATION', false)
            ->assertSee('agency-line', false)
            ->assertSee('2.33cm', false)
            ->assertSee('width: auto', false)
            ->assertDontSee('Appendix 58', false)
            ->assertSee('brand-header', false)
            ->assertDontSee('data:image/', false)
            ->assertSee('bagong-pilipinas-form-logo', false)
            ->assertSee('owwa-form-logo', false)
            ->assertSee('brand-logo--left', false)
            ->assertSee('brand-logo--right', false)
            ->assertDontSee('Generated on', false)
            ->assertSee('Times New Roman', false)
            ->assertSee('Entity Name:', false)
            ->assertSee('Fund Cluster:', false)
            ->assertSee('Item :', false)
            ->assertSee('Stock No. :', false)
            ->assertSee('Unit of Measurement :', false)
            ->assertSee('header-box', false)
            ->assertSee('>Receipt<', false)
            ->assertSee('>Issue<', false)
            ->assertSee('>Balance<', false)
            ->assertSee('No. of Days to Consume', false)
            ->assertSee($item->name, false)
            ->assertDontSee('Receipt Qty.', false);
    }

    public function test_official_sync_path_still_used_for_small_menu_export(): void
    {
        Queue::fake();

        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0']);

        $result = $component->instance()->runStockCardsExport('xlsx', 'selected', 'one');

        $this->assertSame('sync', $result);
        Queue::assertNotPushed(GenerateStockCardExportJob::class);
    }

    public function test_fast_sync_livewire_starts_download_without_queue(): void
    {
        Queue::fake();

        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0']);

        $result = $component->instance()->runStockCardsFastSyncExport('selected');

        $this->assertSame('sync', $result);
        $component->assertSet('exportBusy', true);
        Queue::assertNothingPushed();
    }

    public function test_fast_sync_preview_delivery_expands_same_modal(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0'])
            ->callAction('exportStockCardsFastSync', [
                'export_scope' => 'selected',
                'export_delivery' => 'preview',
            ])
            ->assertActionMounted('exportStockCardsFastSync');

        $this->assertStringContainsString('preview=1', (string) $component->instance()->fastPrintPreviewUrl);
        $this->assertStringNotContainsString('preview=1', (string) $component->instance()->fastPrintDownloadUrl);
        $this->assertNotEmpty($component->instance()->fastPrintDownloadUrl);

        $previewHtml = view(
            'filament.pages.partials.stock-card-fast-preview-modal',
            ['previewUrl' => $component->instance()->fastPrintPreviewUrl],
        )->render();
        $this->assertStringContainsString('Print preview', $previewHtml);
        $this->assertStringContainsString('owwa-fast-export-preview-panel', $previewHtml);
        $this->assertStringContainsString('preview=1', $previewHtml);
    }

    public function test_fast_api_route_is_removed(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('api.stock-card-exports.fast'),
        );
    }

    public function test_fast_excel_route_returns_xlsx_for_selected_pairs(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.stock-cards-fast-xlsx', [
                'category' => $category->id,
                'pairs' => $item->id.':'.$office->id.':0',
            ]))
            ->assertOk()
            ->assertDownload()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
    }

    public function test_fast_excel_livewire_starts_download_without_queue(): void
    {
        Queue::fake();

        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0']);

        $result = $component->instance()->runStockCardsFastExcelExport('selected');

        $this->assertSame('sync', $result);
        $component->assertSet('exportBusy', true);
        $this->assertStringContainsString(
            'stock-cards-fast-xlsx',
            $component->instance()->buildStockCardsFastExcelExportUrl('selected', [$item->id.':'.$office->id.':0']),
        );
        Queue::assertNothingPushed();
    }

    public function test_export_size_loads_after_modal_via_refresh_export_size(): void
    {
        [$custodian, $category] = $this->seedConsumableStock();

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->assertSet('exportSizeReady', false)
            ->call('refreshExportSize', 'all')
            ->assertSet('exportSizeReady', true)
            ->assertSet('exportSizeCount', 1)
            ->assertSet('exportSizeScope', 'all');
    }

    public function test_fast_export_over_batch_size_starts_sync_zip_download(): void
    {
        Queue::fake();

        [$custodian, $category] = $this->seedConsumableStock();

        $keys = [];
        for ($i = 1; $i <= StockLevelExportService::BATCH_SIZE + 1; $i++) {
            $keys[] = $i.':1:0';
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', $keys);

        $result = $component->instance()->runStockCardsFastSyncExport('selected', 'direct');

        $this->assertSame('sync', $result);
        $component->assertSet('exportBusy', true);
        $this->assertStringContainsString(
            'fast_pack=1',
            $component->instance()->buildStockCardsFastExportUrl('selected', $keys),
        );
        Queue::assertNothingPushed();
    }

    public function test_refresh_export_size_bumps_mounted_action_stamp(): void
    {
        [$custodian, $category] = $this->seedConsumableStock();

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('mountedActions', [[
                'name' => 'exportStockCardsFastSync',
                'data' => ['export_scope' => 'all', 'export_size_stamp' => '0'],
            ]])
            ->call('refreshExportSize', 'all')
            ->assertSet('exportSizeReady', true)
            ->assertSet('exportSizeCount', 1);

        $stamp = data_get($component->instance()->mountedActions, '0.data.export_size_stamp');
        $this->assertNotSame('0', $stamp);
        $this->assertNotEmpty($stamp);
    }

    public function test_fast_excel_spreadsheet_contains_stock_card_labels(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        $pairs = collect([[
            'item_id' => $item->id,
            'office_id' => $office->id,
            'unit_cost' => null,
        ]]);

        $spreadsheet = app(\App\Services\StockCardFastExcelExportService::class)->makeSpreadsheet(
            app(\App\Services\StockCardFastPdfExportService::class)->buildCards($pairs),
        );

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Republic of the Philippines', $sheet->getCell('C1')->getValue());
        $this->assertSame('OVERSEAS WORKERS WELFARE ADMINISTRATION', $sheet->getCell('C2')->getValue());
        $this->assertSame('STOCK CARD', $sheet->getCell('C3')->getValue());
        $this->assertSame(14.0, $sheet->getStyle('C1')->getFont()->getSize());
        $this->assertSame('Times New Roman', $sheet->getStyle('C1')->getFont()->getName());
        $this->assertSame(14.0, $sheet->getStyle('C2')->getFont()->getSize());
        $this->assertSame('Times New Roman', $sheet->getStyle('C2')->getFont()->getName());
        $this->assertGreaterThanOrEqual(2, $sheet->getDrawingCollection()->count());
        $expectedHeight = (int) round(2.33 * 96 / 2.54);
        foreach ($sheet->getDrawingCollection() as $drawing) {
            $this->assertSame($expectedHeight, $drawing->getHeight());
            $this->assertGreaterThan(0, $drawing->getWidth());
        }
        $this->assertStringContainsString('Item : '.$item->name, (string) $sheet->getCell('A6')->getValue());
        $this->assertSame('Date', $sheet->getCell('A9')->getValue());
        $this->assertSame('Receipt', $sheet->getCell('C9')->getValue());
        $this->assertSame('Qty.', $sheet->getCell('C10')->getValue());
    }

    public function test_print_view_requires_owner_and_shows_toolbar(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $other = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $htmlPath = StockCardFastPdfExportService::DIRECTORY.'/'.$owner->id.'/file-SC-fast.html';
        $pdfPath = StockCardFastPdfExportService::DIRECTORY.'/'.$owner->id.'/file-SC-fast.pdf';
        Storage::disk('local')->put($htmlPath, '<html><body style="font-family: Times New Roman">STOCK CARD</body></html>');
        Storage::disk('local')->put($pdfPath, '%PDF-fake');

        $url = URL::temporarySignedRoute(
            'owwa.export.stock-cards.print',
            now()->addHour(),
            ['user' => $owner->id, 'file' => 'file-SC-fast.html'],
        );

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee('Print view', false)
            ->assertSee('Download', false)
            ->assertSee('Times New Roman', false);
    }

    public function test_fast_preview_date_range_includes_opening_balance_and_filters_movements(): void
    {
        [$custodian, $category, $office, $item] = $this->seedConsumableStock();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-OLD'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 7,
            'acquisition_date' => '2025-06-01',
            'recorded_by' => $custodian->id,
        ]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.stock-cards-fast', [
                'category' => $category->id,
                'pairs' => $item->id.':'.$office->id.':0',
                'preview' => 1,
                'date_mode' => 'range',
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ]))
            ->assertOk()
            ->assertSee('Balance forwarded', false)
            ->assertDontSee('ACQ-OLD'.$item->id, false)
            ->assertSee('ACQ-FAST'.$item->id, false);
    }

    /**
     * @return array{0: User, 1: ItemCategory, 2: Office, 3: Item}
     */
    protected function seedConsumableStock(): array
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-FAST',
            'name' => 'Fast Trial Bond Paper',
            'unit' => 'ream',
            'reorder_level' => 2,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-FAST'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        return [$custodian, $category, $office, $item];
    }
}
