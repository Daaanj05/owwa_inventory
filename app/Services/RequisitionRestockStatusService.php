<?php

namespace App\Services;

use App\Models\StockPositionRestockFlag;
use App\Support\SupplyOfficeResolver;
use App\Support\UnitCostKey;
use Illuminate\Support\Collection;

class RequisitionRestockStatusService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_AUTOMATIC = 'automatic';

    public const STATUS_MIXED = 'mixed';

    public function __construct(
        protected InventoryStockService $stockService,
        protected SupplyOfficeResolver $supplyOfficeResolver,
    ) {}

    public function resolve(int $itemId, ?int $supplyOfficeId = null): ?string
    {
        return $this->resolveForItems([$itemId], $supplyOfficeId)[$itemId] ?? null;
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<int, string|null>
     */
    public function resolveForItems(array $itemIds, ?int $supplyOfficeId = null): array
    {
        $itemIds = collect($itemIds)
            ->map(fn (int|string $itemId): int => (int) $itemId)
            ->filter(fn (int $itemId): bool => $itemId > 0)
            ->unique()
            ->values();

        $officeId = $supplyOfficeId ?? $this->supplyOfficeResolver->resolve();

        if ($itemIds->isEmpty() || $officeId === null) {
            return $itemIds->mapWithKeys(fn (int $itemId): array => [$itemId => null])->all();
        }

        $positionCounts = array_fill_keys($itemIds->all(), 0);

        foreach ($this->stockService->getActiveStockPositionKeys() as $positionKey => $active) {
            if (! $active) {
                continue;
            }

            $position = UnitCostKey::parsePositionKey($positionKey);

            if ($position === null
                || $position['office_id'] !== $officeId
                || ! array_key_exists($position['item_id'], $positionCounts)) {
                continue;
            }

            $positionCounts[$position['item_id']]++;
        }

        $inactiveFlags = StockPositionRestockFlag::query()
            ->whereIn('item_id', $itemIds->all())
            ->where('office_id', $officeId)
            ->where('is_inactive_for_restock', true)
            ->get()
            ->groupBy('item_id');

        return $itemIds
            ->mapWithKeys(fn (int $itemId): array => [
                $itemId => $this->aggregateStatus(
                    $inactiveFlags->get($itemId, collect()),
                    $positionCounts[$itemId] ?? 0,
                ),
            ])
            ->all();
    }

    public function displayLabel(?string $status): ?string
    {
        return match ($status) {
            self::STATUS_MANUAL => 'Inactive',
            self::STATUS_AUTOMATIC => 'Inactive — no stock for 1 year',
            self::STATUS_MIXED => 'Some stock positions inactive',
            default => null,
        };
    }

    /**
     * @param  Collection<int, StockPositionRestockFlag>  $inactiveFlags
     */
    protected function aggregateStatus(Collection $inactiveFlags, int $positionCount): string
    {
        if ($inactiveFlags->isEmpty()) {
            return self::STATUS_ACTIVE;
        }

        if ($positionCount > $inactiveFlags->count()) {
            return self::STATUS_MIXED;
        }

        $allAutomatic = $inactiveFlags->every(
            fn (StockPositionRestockFlag $flag): bool => $flag->inactive_source === StockPositionRestockFlag::SOURCE_AUTOMATIC,
        );

        return $allAutomatic ? self::STATUS_AUTOMATIC : self::STATUS_MANUAL;
    }
}
