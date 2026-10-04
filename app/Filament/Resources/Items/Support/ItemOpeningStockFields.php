<?php

namespace App\Filament\Resources\Items\Support;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\StockOpeningBalance;
use App\Support\SupplyOfficeResolver;

/**
 * Eligibility helpers for Acquisitions → Received opening-balance recording.
 */
class ItemOpeningStockFields
{
    public static function resolveRegionalOfficeId(): ?int
    {
        return app(SupplyOfficeResolver::class)->resolve();
    }

    /**
     * True when the item has no opening balance and no acquisition at the regional office.
     */
    public static function canSetStartingStock(Item $item, ?int $officeId = null): bool
    {
        if ($item->archived_at !== null) {
            return false;
        }

        $officeId ??= self::resolveRegionalOfficeId();
        if ($officeId === null || $officeId < 1) {
            return false;
        }

        return ! isset(self::itemIdsBlockedFromStartingStock([(int) $item->id], (int) $officeId)[$item->id]);
    }

    /**
     * Items that already have an opening balance or acquisition at the office.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, true>
     */
    public static function itemIdsBlockedFromStartingStock(array $itemIds, int $officeId): array
    {
        if ($itemIds === [] || $officeId < 1) {
            return [];
        }

        $blocked = [];

        foreach (StockOpeningBalance::query()
            ->where('office_id', $officeId)
            ->whereIn('item_id', $itemIds)
            ->distinct()
            ->pluck('item_id') as $itemId) {
            $blocked[(int) $itemId] = true;
        }

        foreach (Acquisition::query()
            ->where('office_id', $officeId)
            ->whereIn('item_id', $itemIds)
            ->distinct()
            ->pluck('item_id') as $itemId) {
            $blocked[(int) $itemId] = true;
        }

        return $blocked;
    }
}
