<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StockLedgerModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_custodian_can_open_stock_ledger_modal_for_visible_item(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MODAL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 12,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('openStockLedger', $item->id, $office->id, 0.0)
            ->assertActionMounted('viewStockLedger')
            ->assertSet('ledgerExportUrl', fn (?string $url): bool => is_string($url) && str_contains($url, 'stock-cards') && str_contains($url, 'pairs='))
            ->assertSet('ledgerExportPdfUrl', fn (?string $url): bool => is_string($url) && str_contains($url, 'format=pdf'));
    }

    public function test_stock_ledger_modal_footer_export_dispatches_busy_download(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MODAL-EXPORT',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 8,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $links = app(\App\Services\StockLedgerViewService::class)->exportLinks($item, $office, 0.0);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set([
                'ledgerExportUrl' => $links['exportUrl'],
                'ledgerExportPdfUrl' => $links['exportPdfUrl'],
                'ledgerExportLabel' => $links['exportLabel'],
                'ledgerExportPdfLabel' => $links['exportPdfLabel'],
                'ledgerExportTitle' => $links['title'],
            ])
            ->callAction(
                ['viewStockLedger', 'exportLedgerExcel'],
                arguments: [
                    'viewStockLedger' => [
                        'itemId' => $item->id,
                        'officeId' => $office->id,
                        'unitCost' => 0.0,
                    ],
                ],
            )
            ->assertSet('exportBusy', true);

        $html = view('filament.pages.partials.stock-ledger-modal', [
            'ledger' => app(\App\Services\StockLedgerViewService::class)->present($item, $office, 0.0),
        ])->render();

        $this->assertStringNotContainsString('owwaBusyNavigate', $html);
        $this->assertStringNotContainsString('data-owwa-export-url', $html);
    }

    public function test_open_stock_ledger_rejects_item_not_in_visible_list(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $visibleItem = Item::factory()->create(['item_category_id' => $category->id]);
        $hiddenItem = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MODAL-VISIBLE',
            'item_id' => $visibleItem->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('openStockLedger', $hiddenItem->id, $office->id, 0.0)
            ->assertStatus(403);
    }
}
