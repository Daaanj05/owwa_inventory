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

        $units = [];

        $opening = DB::transaction(function () use ($item, $officeId, $quantity, $normalizedCost, $recordedBy, $slug, &$units): StockOpeningBalance {
            $opening = StockOpeningBalance::query()->create([
                'item_id' => $item->id,
                'office_id' => $officeId,
                'unit_cost' => $normalizedCost,
                'quantity' => $quantity,
                'recorded_by' => $recordedBy?->id,
                'recorded_at' => now(),
            ]);

            if (in_array($slug, ['ppe', 'semi_expendable'], true)) {
                $units = $this->unitService->mintUnitsForItem(
                    item: $item,
                    officeId: $officeId,
                    quantity: $quantity,
                    unitCost: $normalizedCost,
                    acquisitionId: null,
                );
            }

            return $opening;
        });

        $this->stockService->forgetMovementTotalsCache();

        return [
            'opening' => $opening,
            'units' => $units,
        ];
    }
}
