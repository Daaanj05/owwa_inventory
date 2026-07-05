<?php

namespace App\Support;

use App\Models\AcquisitionPaperwork;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\RequisitionItem;
use App\Services\InventoryStockService;
use App\Services\PhysicalCountStockReconciliationService;
use Illuminate\Support\Collection;
use RuntimeException;

class DemoStockIntegrityVerifier
{
    public function __construct(
        protected InventoryStockService $stockService,
        protected PhysicalCountStockReconciliationService $reconciliation,
    ) {}

    public function assertDemoLedger(): void
    {
        $this->assertPaperworkCounts();
        $this->assertExpectedStockLevels();
        $this->assertIssuanceRequisitionLinks();
        $this->assertRequisitionQuantitiesIssued();
        $this->assertPhysicalCountRpciBalances();
        $this->assertPhysicalCountPropertyLinks();
        $this->assertReconciliationDrift();
    }

    protected function assertPaperworkCounts(): void
    {
        $consumables = ItemCategory::query()->where('name', DemoAcquisitionPaperworkCatalog::CATEGORY_CONSUMABLES)->first();
        $semi = ItemCategory::query()->where('name', DemoAcquisitionPaperworkCatalog::CATEGORY_SEMI)->first();
        $ppe = ItemCategory::query()->where('name', DemoAcquisitionPaperworkCatalog::CATEGORY_PPE)->first();

        foreach ([$consumables, $semi, $ppe] as $category) {
            if ($category === null) {
                continue;
            }

            $count = AcquisitionPaperwork::query()
                ->where('item_category_id', $category->id)
                ->count();

            if ($count < 4) {
                throw new RuntimeException("Expected at least 4 paperwork cases for {$category->name}, found {$count}.");
            }
        }
    }

    protected function assertExpectedStockLevels(): void
    {
        $offices = Office::query()
            ->whereIn('code', [DemoStockLedgerCatalog::REGIONAL_OFFICE, DemoStockLedgerCatalog::SATELLITE_OFFICE])
            ->get()
            ->keyBy('code');

        $items = Item::query()
            ->whereIn('item_code', DemoStockLedgerCatalog::allCoreItemCodes())
            ->get()
            ->keyBy('item_code');

        foreach (DemoStockLedgerCatalog::corePositions() as $position) {
            $item = $items[$position['item_code']] ?? null;
            $office = $offices[$position['office_code']] ?? null;

            if ($item === null || $office === null) {
                continue;
            }

            $actual = $this->stockService->getStock($item->id, $office->id);
            $expected = $position['expected_stock'];

            if ($actual !== $expected) {
                throw new RuntimeException(
                    "Stock mismatch for {$position['item_code']} @ {$position['office_code']}: expected {$expected}, got {$actual}.",
                );
            }
        }
    }

    protected function assertIssuanceRequisitionLinks(): void
    {
        $orphans = Issuance::query()->whereNull('requisition_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException("Found {$orphans} issuances without requisition_id.");
        }
    }

    protected function assertRequisitionQuantitiesIssued(): void
    {
        RequisitionItem::query()->each(function (RequisitionItem $line): void {
            $issued = (int) Issuance::query()
                ->where('requisition_id', $line->requisition_id)
                ->where('item_id', $line->item_id)
                ->sum('quantity');

            $recorded = (int) ($line->quantity_issued ?? 0);

            if ($issued !== $recorded) {
                throw new RuntimeException(
                    "Requisition line {$line->id}: quantity_issued ({$recorded}) != sum issuances ({$issued}).",
                );
            }
        });
    }

    protected function assertPhysicalCountRpciBalances(): void
    {
        $session = PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCI-2026')->first();

        if ($session === null) {
            throw new RuntimeException('Missing PC-DEMO-RPCI-2026 session.');
        }

        $officeId = (int) $session->office_id;
        $lines = PhysicalCountLine::query()
            ->where('physical_count_session_id', $session->id)
            ->whereNotNull('item_id')
            ->get();

        foreach ($lines as $line) {
            $expected = $this->stockService->getStock((int) $line->item_id, $officeId);
            $balance = (int) $line->balance_per_card;

            if ($balance !== $expected) {
                $itemCode = Item::query()->whereKey($line->item_id)->value('item_code');

                throw new RuntimeException(
                    "PC-DEMO-RPCI balance mismatch for {$itemCode}: line {$balance}, stock {$expected}.",
                );
            }
        }
    }

    protected function assertPhysicalCountPropertyLinks(): void
    {
        foreach (['PC-DEMO-RPCSP-2026', 'PC-DEMO-RPCPPE-2026'] as $referenceCode) {
            $session = PhysicalCountSession::query()->where('reference_code', $referenceCode)->first();

            if ($session === null) {
                throw new RuntimeException("Missing {$referenceCode} session.");
            }

            PhysicalCountLine::query()
                ->where('physical_count_session_id', $session->id)
                ->each(function (PhysicalCountLine $line): void {
                    if (blank($line->property_number)) {
                        throw new RuntimeException("Physical count line {$line->id} missing property_number.");
                    }

                    $itemCode = $line->item?->item_code ?? Item::query()->whereKey($line->item_id)->value('item_code');

                    if ($itemCode !== null && $line->stock_number !== null && $line->stock_number !== $itemCode) {
                        throw new RuntimeException(
                            "PC line {$line->id}: stock_number {$line->stock_number} != item_code {$itemCode}.",
                        );
                    }
                });
        }
    }

    protected function assertReconciliationDrift(): void
    {
        $office = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);

        if ($office === null) {
            return;
        }

        foreach (['Semi-Expendable', 'Property, Plant and Equipment'] as $categoryName) {
            $category = ItemCategory::query()->where('name', $categoryName)->first();

            if ($category === null) {
                continue;
            }

            $report = $this->reconciliation->reconcile($office->id, $category->id);

            $coreItemIds = Item::query()
                ->where('item_category_id', $category->id)
                ->whereIn('item_code', $categoryName === 'Semi-Expendable'
                    ? DemoStockLedgerCatalog::coreSemiCodes()
                    : DemoStockLedgerCatalog::corePpeCodes())
                ->pluck('id');

            $coreDrift = Collection::make($report['items'])
                ->whereIn('item_id', $coreItemIds)
                ->sum(fn (array $row): int => abs((int) $row['drift']));

            if ($coreDrift !== 0) {
                throw new RuntimeException("Reconciliation drift for core {$categoryName} items at OWWA-IVA.");
            }
        }
    }
}
