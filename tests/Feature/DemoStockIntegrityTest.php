<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Services\InventoryStockService;
use App\Services\PhysicalCountStockReconciliationService;
use App\Support\DemoStockLedgerCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_produces_reconciled_core_ledger(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('demo:reset-inventory', ['--force' => true])
            ->assertSuccessful();

        $office = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);
        $this->assertNotNull($office);

        $stockService = app(InventoryStockService::class);

        $con001 = Item::query()->where('item_code', 'CON-001')->firstOrFail();
        $this->assertSame(
            DemoStockLedgerCatalog::expectedStock('CON-001', DemoStockLedgerCatalog::REGIONAL_OFFICE),
            $stockService->getStock($con001->id, $office->id),
        );

        $satellite = Office::query()->firstWhere('code', DemoStockLedgerCatalog::SATELLITE_OFFICE);
        $this->assertNotNull($satellite);
        $this->assertGreaterThan(
            0,
            $stockService->getStock($con001->id, $satellite->id),
        );

        $rpci = PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCI-2026')->firstOrFail();
        $rpciLine = PhysicalCountLine::query()
            ->where('physical_count_session_id', $rpci->id)
            ->where('item_id', $con001->id)
            ->firstOrFail();

        $this->assertSame(
            $stockService->getStock($con001->id, $office->id),
            (int) $rpciLine->balance_per_card,
        );

        $semiCategory = ItemCategory::query()->where('name', 'Semi-Expendable')->firstOrFail();
        $report = app(PhysicalCountStockReconciliationService::class)->reconcile($office->id, $semiCategory->id);

        $coreSemiIds = Item::query()
            ->whereIn('item_code', DemoStockLedgerCatalog::coreSemiCodes())
            ->pluck('id');

        $coreDrift = collect($report['items'])
            ->whereIn('item_id', $coreSemiIds)
            ->sum(fn (array $row): int => abs((int) $row['drift']));

        $this->assertSame(0, $coreDrift);

        $consumables = ItemCategory::query()->where('name', 'Consumables')->firstOrFail();
        $this->assertGreaterThanOrEqual(
            4,
            AcquisitionPaperwork::query()->where('item_category_id', $consumables->id)->count(),
        );
    }
}
