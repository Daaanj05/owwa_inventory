<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\StockOpeningBalance;
use App\Models\User;
use App\Support\PpeValueCategory;
use App\Support\SemiExpendableValueCategory;
use App\Support\UnitCostKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    public function __construct(
        protected AcquisitionUnitService $unitService,
        protected InventoryStockService $stockService,
    ) {}

    /**
     * Persist a draft opening-balance line without minting units or affecting on-hand stock maps.
     *
     * @return array{opening: StockOpeningBalance, units: array<int, InventoryUnit>}
     *
     * @throws ValidationException
     */
    public function createDraftLine(
        Item $item,
        int $officeId,
        int $quantity,
        ?float $unitCost,
        ?User $recordedBy = null,
        ?int $batchId = null,
    ): array {
        $item->loadMissing('category');

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Starting stock quantity must be at least 1.',
            ]);
        }

        $slug = $item->category?->getTemplateSlug();
        $normalizedCost = (float) UnitCostKey::normalize($unitCost);

        if (in_array($slug, ['ppe', 'semi_expendable'], true) && $unitCost === null) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Unit cost is required for PPE and semi-expendable starting stock.',
            ]);
        }

        if ($slug === 'ppe') {
            PpeValueCategory::assertMinimumForPpe($unitCost);
        }

        if ($slug === 'semi_expendable' && $unitCost !== null) {
            SemiExpendableValueCategory::assertWithinSemiCap((float) $unitCost);
        }

        if (StockOpeningBalance::findForPosition($item->id, $officeId, $normalizedCost) !== null) {
            throw ValidationException::withMessages([
                'quantity' => 'Starting stock already exists for this item, office, and unit cost. Duplicate import is blocked.',
            ]);
        }

        $opening = StockOpeningBalance::query()->create([
            'batch_id' => $batchId,
            'item_id' => $item->id,
            'office_id' => $officeId,
            'unit_cost' => $normalizedCost,
            'quantity' => $quantity,
            'recorded_by' => $recordedBy?->id,
            'recorded_at' => now(),
        ]);

        return [
            'opening' => $opening,
            'units' => [],
        ];
    }

    /**
     * @return array<int, InventoryUnit>
     */
    public function mintUnitsForConfirmedLine(StockOpeningBalance $opening): array
    {
        $opening->loadMissing('item.category');
        $item = $opening->item;
        if ($item === null) {
            return [];
        }

        $slug = $item->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return [];
        }

        return $this->unitService->mintUnitsForItem(
            item: $item,
            officeId: (int) $opening->office_id,
            quantity: (int) $opening->quantity,
            unitCost: $opening->unit_cost !== null ? (float) $opening->unit_cost : null,
            acquisitionId: null,
        );
    }

    /**
     * @return array{opening: StockOpeningBalance, units: array<int, InventoryUnit>}
     *
     * @throws ValidationException
     */
    public function setOpeningStock(
        Item $item,
        int $officeId,
        int $quantity,
        ?float $unitCost,
        ?User $recordedBy = null,
        ?int $batchId = null,
    ): array {
        $result = DB::transaction(function () use ($item, $officeId, $quantity, $unitCost, $recordedBy, $batchId): array {
            $draft = $this->createDraftLine(
                item: $item,
                officeId: $officeId,
                quantity: $quantity,
                unitCost: $unitCost,
                recordedBy: $recordedBy,
                batchId: $batchId,
            );

            $units = $this->mintUnitsForConfirmedLine($draft['opening']);

            return [
                'opening' => $draft['opening'],
                'units' => $units,
            ];
        });

        $this->stockService->forgetMovementTotalsCache();

        return $result;
    }
}
