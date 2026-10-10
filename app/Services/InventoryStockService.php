<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockBucket;
use App\Models\StockPositionRestockFlag;
use App\Support\SemiExpendableValueCategory;
use App\Support\UnitCostKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryStockService
{
    /**
     * Request-scoped movement totals so repeated getStock() calls reuse one aggregation pass.
     *
     * @var array{
     *   opening: array<string, int>,
     *   acq: array<string, int>,
     *   inTransfers: array<string, int>,
     *   issuances: array<string, int>,
     *   outTransfers: array<string, int>,
     *   disposals: array<string, int>
     * }|null
     */
    protected static ?array $movementTotalsMaps = null;

    /**
     * Get current stock quantity for an item at an office (sum across all unit-cost buckets).
     */
    public function getStock(int $itemId, int $officeId): int
    {
        $maps = $this->getMovementTotalsMaps();
        $prefix = "{$itemId}_{$officeId}_";
        $total = 0;

        foreach ($this->positionKeysFromMaps($maps) as $key) {
            if (str_starts_with($key, $prefix)) {
                $total += $this->calculateStockFromMaps($key, $maps);
            }
        }

        return $total;
    }

    public function forgetMovementTotalsCache(): void
    {
        self::$movementTotalsMaps = null;
        $this->bumpStockSummaryCacheVersion();
    }

    public function bumpStockSummaryCacheVersion(): void
    {
        $version = (int) Cache::get('inventory.stock_summary.version', 0);
        Cache::forever('inventory.stock_summary.version', $version + 1);
    }

    /**
     * @deprecated Use bumpStockSummaryCacheVersion()
     */
    public function bumpLowStockCountCacheVersion(): void
    {
        $this->bumpStockSummaryCacheVersion();
    }

    protected function stockSummaryCacheVersion(): int
    {
        return (int) Cache::get('inventory.stock_summary.version', 0);
    }

    /**
     * @param  array<int, int>|null  $officeIds
     */
    protected function lowStockCountCacheKey(?array $officeIds): string
    {
        $version = $this->stockSummaryCacheVersion();
        $scope = ($officeIds === null || $officeIds === [])
            ? 'all'
            : implode('-', array_map('intval', $officeIds));

        return "inventory.low_stock_count.v{$version}.{$scope}";
    }

    protected function categoryOfficeSummaryCacheKey(int $categoryId, int $officeId): string
    {
        return 'inventory.category_office_summary.v'.$this->stockSummaryCacheVersion()
            .".{$categoryId}.{$officeId}";
    }

    public function lowStockCount(?array $officeIds = null, ?int $fiscalYearId = null): int
    {
        unset($fiscalYearId);

        return (int) Cache::remember(
            $this->lowStockCountCacheKey($officeIds),
            60,
            fn (): int => $this->computeLowStockCount($officeIds),
        );
    }

    /**
     * @param  array<int, int>|null  $officeIds
     */
    protected function computeLowStockCount(?array $officeIds): int
    {
        $singleOfficeId = ($officeIds !== null && count($officeIds) === 1)
            ? (int) $officeIds[0]
            : null;

        if ($singleOfficeId !== null) {
            return $this->computeLowStockCountForOffice($singleOfficeId);
        }

        $activePairs = $this->getActiveItemOfficePairKeys();
        $itemIds = [];
        foreach (array_keys($activePairs) as $key) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));
            if ($officeIds !== null && $officeIds !== [] && ! in_array($officeId, $officeIds, true)) {
                continue;
            }
            $itemIds[$itemId] = true;
        }

        if ($itemIds === []) {
            return 0;
        }

        $items = DB::table('items')
            ->whereIn('id', array_keys($itemIds))
            ->whereIn('item_category_id', $this->consumableCategoryIds())
            ->where('reorder_level', '>', 0)
            ->whereNull('archived_at')
            ->pluck('reorder_level', 'id');

        $count = 0;
        foreach (array_keys($activePairs) as $key) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));
            if ($officeIds !== null && $officeIds !== [] && ! in_array($officeId, $officeIds, true)) {
                continue;
            }
            if (! isset($items[$itemId])) {
                continue;
            }

            if ($this->getStock($itemId, $officeId) <= (int) $items[$itemId]) {
                $count++;
            }
        }

        return $count;
    }

    protected function computeLowStockCountForOffice(int $officeId): int
    {
        $positionKeys = array_keys($this->getActiveStockPositionKeys(null, $officeId));
        if ($positionKeys === []) {
            return 0;
        }

        $itemIds = [];
        foreach ($positionKeys as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null) {
                continue;
            }
            $itemIds[$parsed['item_id']] = true;
        }

        if ($itemIds === []) {
            return 0;
        }

        $items = DB::table('items')
            ->whereIn('id', array_keys($itemIds))
            ->whereIn('item_category_id', $this->consumableCategoryIds())
            ->where('reorder_level', '>', 0)
            ->whereNull('archived_at')
            ->pluck('reorder_level', 'id');

        if ($items->isEmpty()) {
            return 0;
        }

        $scopedItemIds = $items->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $maps = $this->buildScopedMovementTotalsMaps($scopedItemIds, $officeId);
        $aggregateByPair = [];

        foreach ($positionKeys as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null || ! isset($items[$parsed['item_id']])) {
                continue;
            }

            $pairKey = "{$parsed['item_id']}_{$parsed['office_id']}";
            $aggregateByPair[$pairKey] = ($aggregateByPair[$pairKey] ?? 0)
                + $this->calculateStockFromMaps($positionKey, $maps);
        }

        $count = 0;
        foreach ($aggregateByPair as $pairKey => $stock) {
            [$itemId] = array_map('intval', explode('_', (string) $pairKey, 2));
            if (! isset($items[$itemId])) {
                continue;
            }

            if ($stock <= (int) $items[$itemId]) {
                $count++;
            }
        }

        return $count;
    }

    public function getStockForUnitCost(int $itemId, int $officeId, ?float $unitCost): int
    {
        $maps = $this->getMovementTotalsMaps();
        $key = UnitCostKey::positionKey($itemId, $officeId, $unitCost);

        return $this->calculateStockFromMaps($key, $maps);
    }

    /**
     * Stock available for procurement cover (excludes inactive-for-restock positions).
     */
    public function getActiveRestockStock(int $itemId, int $officeId): int
    {
        $maps = $this->getMovementTotalsMaps();
        $prefix = "{$itemId}_{$officeId}_";
        $total = 0;

        foreach ($this->positionKeysFromMaps($maps) as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $parsed = UnitCostKey::parsePositionKey($key);
            if ($parsed === null) {
                continue;
            }

            if (StockPositionRestockFlag::isInactiveForRestock($itemId, $officeId, $parsed['unit_cost'])) {
                continue;
            }

            $total += $this->calculateStockFromMaps($key, $maps);
        }

        return $total;
    }

    /**
     * Legacy on-hand stock (inactive-for-restock positions with qty > 0).
     */
    public function getLegacyOnHandStock(int $itemId, int $officeId): int
    {
        $maps = $this->getMovementTotalsMaps();
        $prefix = "{$itemId}_{$officeId}_";
        $total = 0;

        foreach ($this->positionKeysFromMaps($maps) as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $parsed = UnitCostKey::parsePositionKey($key);
            if ($parsed === null) {
                continue;
            }

            if (! StockPositionRestockFlag::isInactiveForRestock($itemId, $officeId, $parsed['unit_cost'])) {
                continue;
            }

            $total += $this->calculateStockFromMaps($key, $maps);
        }

        return $total;
    }

    /**
     * @return array<int, int> item_id => quantity
     */
    public function getStockByOffice(int $officeId): array
    {
        $itemIds = Item::pluck('id')->toArray();
        $result = [];
        foreach ($itemIds as $id) {
            $result[$id] = $this->getStock($id, $officeId);
        }

        return $result;
    }

    public function isLowStock(Item $item, int $officeId): bool
    {
        $item->loadMissing('category');
        if ($item->category?->getTemplateSlug() !== 'consumables') {
            return false;
        }

        if (! $this->hasInventoryActivity($item->id, $officeId)) {
            return false;
        }

        $stock = $this->getStock($item->id, $officeId);

        return $stock <= $item->reorder_level && $item->reorder_level > 0;
    }

    /**
     * @return array<int, int>
     */
    protected function consumableCategoryIds(): array
    {
        return ItemCategory::query()
            ->get(['id', 'name'])
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'consumables')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    protected function categoryTracksReorder(?string $categoryName): bool
    {
        return (new ItemCategory(['name' => $categoryName]))->getTemplateSlug() === 'consumables';
    }

    /**
     * @return array<string, true>
     */
    public function getActiveItemOfficePairKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->getActiveStockPositionKeys()) as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null) {
                continue;
            }
            $keys["{$parsed['item_id']}_{$parsed['office_id']}"] = true;
        }

        return $keys;
    }

    /**
     * Stock positions (item × office × unit cost) with inventory history.
     *
     * @param  array<int, int>|null  $itemIds
     * @return array<string, true>
     */
    public function getActiveStockPositionKeys(?array $itemIds = null, ?int $officeId = null): array
    {
        if ($itemIds !== null && $itemIds === []) {
            return [];
        }

        $keys = [];

        $addKeys = function (Collection $rows, string $officeColumn, ?string $costColumn = 'unit_cost') use (&$keys): void {
            foreach ($rows as $row) {
                $cost = $costColumn !== null ? ($row->{$costColumn} ?? null) : null;
                $keys[UnitCostKey::positionKey(
                    (int) $row->item_id,
                    (int) $row->{$officeColumn},
                    $cost !== null ? (float) $cost : null,
                )] = true;
            }
        };

        $openings = DB::table('stock_opening_balances')
            ->leftJoin(
                'stock_opening_balance_batches',
                'stock_opening_balances.batch_id',
                '=',
                'stock_opening_balance_batches.id',
            )
            ->where(function ($query): void {
                $query->whereNull('stock_opening_balances.batch_id')
                    ->orWhereNotNull('stock_opening_balance_batches.confirmed_at');
            })
            ->when($itemIds !== null, fn ($q) => $q->whereIn('stock_opening_balances.item_id', $itemIds))
            ->when($officeId !== null, fn ($q) => $q->where('stock_opening_balances.office_id', $officeId))
            ->select(
                'stock_opening_balances.item_id',
                'stock_opening_balances.office_id',
                'stock_opening_balances.unit_cost',
            )
            ->distinct()
            ->get();
        $addKeys($openings, 'office_id');

        $scopeTable = function (string $table, string $officeColumn) use ($itemIds, $officeId) {
            return DB::table($table)
                ->whereNull('deleted_at')
                ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
                ->when($officeId !== null, fn ($q) => $q->where($officeColumn, $officeId))
                ->select('item_id', "{$officeColumn} as office_id", 'unit_cost')
                ->distinct()
                ->get();
        };

        $addKeys($scopeTable('acquisitions', 'office_id'), 'office_id');
        $addKeys($scopeTable('issuances', 'office_id'), 'office_id');
        $addKeys(
            DB::table('disposals')
                ->join('disposal_batches', 'disposals.disposal_batch_id', '=', 'disposal_batches.id')
                ->whereNull('disposals.deleted_at')
                ->whereNotNull('disposal_batches.confirmed_at')
                ->when($itemIds !== null, fn ($q) => $q->whereIn('disposals.item_id', $itemIds))
                ->when($officeId !== null, fn ($q) => $q->where('disposals.office_id', $officeId))
                ->select('disposals.item_id', 'disposals.office_id', 'disposals.acquisition_cost as unit_cost')
                ->distinct()
                ->get(),
            'office_id',
        );
        $addKeys($scopeTable('transfers', 'from_office_id'), 'office_id');
        $addKeys($scopeTable('transfers', 'to_office_id'), 'office_id');

        return $keys;
    }

    public function hasInventoryActivity(int $itemId, int $officeId): bool
    {
        return isset($this->getActiveItemOfficePairKeys()["{$itemId}_{$officeId}"]);
    }

    /**
     * @return Collection<int, object{
     *     item_id: int,
     *     item_name: string,
     *     category_name: string,
     *     office_id: int,
     *     office_name: string,
     *     unit_cost: float,
     *     property_number: ?string,
     *     property_class: ?string,
     *     value_type: ?string,
     *     stock: int,
     *     reorder_level: int,
     *     is_low: bool,
     *     is_inactive_for_restock: bool,
     *     inactive_source: ?string,
     *     restock_status_label: string,
     *     position_key: string
     * }>
     */
    /**
     * Lightweight positions for stock-card export pairing (no stock qty / movement totals).
     *
     * @return Collection<int, object{
     *     item_id: int,
     *     item_name: string,
     *     office_id: int,
     *     office_name: string,
     *     unit_cost: float|null,
     *     is_inactive_for_restock: bool,
     *     position_key: string
     * }>
     */
    public function listExportStockPositions(?int $categoryId = null, ?int $officeId = null): Collection
    {
        $scopedItemIds = null;
        if ($categoryId !== null) {
            $scopedItemIds = DB::table('items')
                ->where('item_category_id', $categoryId)
                ->whereNull('archived_at')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($scopedItemIds === []) {
                return collect();
            }
        }

        $positionKeys = array_keys($this->getActiveStockPositionKeys($scopedItemIds, $officeId));
        if ($positionKeys === []) {
            return collect();
        }

        $parsedPositions = [];
        $itemIds = [];
        $officeIds = [];

        foreach ($positionKeys as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null) {
                continue;
            }
            $parsedPositions[] = array_merge($parsed, ['position_key' => $positionKey]);
            $itemIds[$parsed['item_id']] = true;
            $officeIds[$parsed['office_id']] = true;
        }

        $items = DB::table('items')
            ->whereIn('id', array_keys($itemIds))
            ->whereNull('archived_at')
            ->when($categoryId !== null, fn ($q) => $q->where('item_category_id', $categoryId))
            ->pluck('name', 'id');

        if ($items->isEmpty()) {
            return collect();
        }

        $offices = DB::table('offices')
            ->whereIn('id', array_keys($officeIds))
            ->whereNull('archived_at')
            ->pluck('name', 'id');

        $listItemIds = $items->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $flagsByPosition = $this->restockFlagsByPosition($listItemIds);
        $rows = collect();

        foreach ($parsedPositions as $position) {
            $itemName = $items[$position['item_id']] ?? null;
            if ($itemName === null) {
                continue;
            }

            $officeName = $offices[$position['office_id']] ?? null;
            if ($officeName === null) {
                continue;
            }

            $costKey = UnitCostKey::normalize($position['unit_cost']);
            $flag = $flagsByPosition[$position['item_id'].'_'.$position['office_id'].'_'.$costKey] ?? null;

            $rows->push((object) [
                'item_id' => $position['item_id'],
                'item_name' => $itemName,
                'office_id' => $position['office_id'],
                'office_name' => $officeName,
                'unit_cost' => $position['unit_cost'],
                'is_inactive_for_restock' => (bool) ($flag?->is_inactive_for_restock ?? false),
                'position_key' => $position['position_key'],
            ]);
        }

        return $rows->sortBy(['item_name', 'office_name', 'unit_cost'])->values();
    }

    public function getStockLevelsList(?int $categoryId = null, ?int $officeId = null): Collection
    {
        $scopedItemIds = null;
        if ($categoryId !== null) {
            $scopedItemIds = DB::table('items')
                ->where('item_category_id', $categoryId)
                ->whereNull('archived_at')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($scopedItemIds === []) {
                return collect();
            }
        }

        $useScopedMaps = $categoryId !== null || $officeId !== null;
        $positionKeys = array_keys($this->getActiveStockPositionKeys($scopedItemIds, $officeId));
        if ($positionKeys === []) {
            return collect();
        }

        $parsedPositions = [];
        $itemIds = [];
        $officeIds = [];

        foreach ($positionKeys as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null) {
                continue;
            }
            $parsedPositions[] = array_merge($parsed, ['position_key' => $positionKey]);
            $itemIds[$parsed['item_id']] = true;
            $officeIds[$parsed['office_id']] = true;
        }

        $items = DB::table('items')
            ->join('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->whereIn('items.id', array_keys($itemIds))
            ->whereNull('items.archived_at')
            ->when($categoryId !== null, fn ($q) => $q->where('items.item_category_id', $categoryId))
            ->select(
                'items.id',
                'items.name',
                'items.reorder_level',
                'items.property_class',
                'item_categories.name as category_name',
            )
            ->get()
            ->keyBy('id');

        if ($items->isEmpty()) {
            return collect();
        }

        $offices = DB::table('offices')
            ->whereIn('id', array_keys($officeIds))
            ->whereNull('archived_at')
            ->pluck('name', 'id');

        $listItemIds = $items->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $bucketsByItemCost = $this->stockBucketsByItemCost($listItemIds);
        $flagsByPosition = $this->restockFlagsByPosition($listItemIds);

        $maps = $useScopedMaps
            ? $this->buildScopedMovementTotalsMaps($listItemIds, $officeId)
            : $this->getMovementTotalsMaps();
        $aggregateStockByPair = [];
        $rows = collect();

        foreach ($parsedPositions as $position) {
            $item = $items->get($position['item_id']);
            if ($item === null) {
                continue;
            }

            $officeName = $offices[$position['office_id']] ?? null;
            if ($officeName === null) {
                continue;
            }

            $pairKey = "{$position['item_id']}_{$position['office_id']}";
            $stock = $this->calculateStockFromMaps($position['position_key'], $maps);
            $aggregateStockByPair[$pairKey] = ($aggregateStockByPair[$pairKey] ?? 0) + $stock;

            $costKey = UnitCostKey::normalize($position['unit_cost']);
            $bucket = $bucketsByItemCost[$position['item_id'].'_'.$costKey] ?? null;
            $flag = $flagsByPosition[$position['item_id'].'_'.$position['office_id'].'_'.$costKey] ?? null;
            $tracksReorder = $this->categoryTracksReorder($item->category_name);

            $rows->push((object) [
                'item_id' => $position['item_id'],
                'item_name' => $item->name,
                'category_name' => $item->category_name,
                'office_id' => $position['office_id'],
                'office_name' => $officeName,
                'unit_cost' => $position['unit_cost'],
                'property_number' => $bucket?->property_number,
                'property_class' => $item->property_class,
                'value_type' => SemiExpendableValueCategory::valueTypeForUnitCost($position['unit_cost']),
                'stock' => $stock,
                'reorder_level' => (int) $item->reorder_level,
                'tracks_reorder' => $tracksReorder,
                'is_low' => false,
                'is_inactive_for_restock' => (bool) ($flag?->is_inactive_for_restock ?? false),
                'inactive_source' => $flag?->inactive_source,
                'restock_status_label' => $flag?->statusLabel() ?? 'Active',
                'position_key' => $position['position_key'],
            ]);
        }

        return $rows
            ->map(function (object $row) use ($aggregateStockByPair): object {
                $pairKey = "{$row->item_id}_{$row->office_id}";
                $aggregate = $aggregateStockByPair[$pairKey] ?? $row->stock;
                $row->is_low = ($row->tracks_reorder ?? false)
                    && $row->reorder_level > 0
                    && $aggregate <= $row->reorder_level;

                return $row;
            })
            ->sortBy(['item_name', 'office_name', 'unit_cost'])
            ->values();
    }

    /**
     * KPI counts for one category at one office without building full stock rows.
     *
     * @return array{total: int, totalStockQty: int, lowCount: int, okCount: int}
     */
    public function summarizeCategoryOfficeStock(int $categoryId, int $officeId): array
    {
        /** @var array{total: int, totalStockQty: int, lowCount: int, okCount: int} */
        return Cache::remember(
            $this->categoryOfficeSummaryCacheKey($categoryId, $officeId),
            60,
            fn (): array => $this->computeCategoryOfficeStockSummary($categoryId, $officeId),
        );
    }

    /**
     * @return array{total: int, totalStockQty: int, lowCount: int, okCount: int}
     */
    protected function computeCategoryOfficeStockSummary(int $categoryId, int $officeId): array
    {
        $tracksReorder = ItemCategory::query()->find($categoryId)?->getTemplateSlug() === 'consumables';

        $items = DB::table('items')
            ->where('item_category_id', $categoryId)
            ->whereNull('archived_at')
            ->pluck('reorder_level', 'id');

        if ($items->isEmpty()) {
            return [
                'total' => 0,
                'totalStockQty' => 0,
                'lowCount' => 0,
                'okCount' => 0,
            ];
        }

        $itemIds = $items->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $positionKeys = array_keys($this->getActiveStockPositionKeys($itemIds, $officeId));
        if ($positionKeys === []) {
            return [
                'total' => 0,
                'totalStockQty' => 0,
                'lowCount' => 0,
                'okCount' => 0,
            ];
        }

        $maps = $this->buildScopedMovementTotalsMaps($itemIds, $officeId);
        $aggregateStockByPair = [];
        $positionStocks = [];

        foreach ($positionKeys as $positionKey) {
            $parsed = UnitCostKey::parsePositionKey($positionKey);
            if ($parsed === null || ! isset($items[$parsed['item_id']])) {
                continue;
            }

            $stock = $this->calculateStockFromMaps($positionKey, $maps);
            $pairKey = "{$parsed['item_id']}_{$parsed['office_id']}";
            $aggregateStockByPair[$pairKey] = ($aggregateStockByPair[$pairKey] ?? 0) + $stock;
            $positionStocks[] = [
                'item_id' => $parsed['item_id'],
                'office_id' => $parsed['office_id'],
                'stock' => $stock,
                'reorder_level' => (int) $items[$parsed['item_id']],
            ];
        }

        $total = count($positionStocks);
        $totalStockQty = (int) array_sum(array_column($positionStocks, 'stock'));
        $lowCount = 0;

        foreach ($positionStocks as $position) {
            $pairKey = "{$position['item_id']}_{$position['office_id']}";
            $aggregate = $aggregateStockByPair[$pairKey] ?? $position['stock'];
            if ($tracksReorder && $position['reorder_level'] > 0 && $aggregate <= $position['reorder_level']) {
                $lowCount++;
            }
        }

        return [
            'total' => $total,
            'totalStockQty' => $totalStockQty,
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    /**
     * Unit costs with stock > 0 at an office for an item.
     *
     * @return array<float, int> unit_cost => qty
     */
    public function getUnitCostBucketsWithStock(int $itemId, int $officeId): array
    {
        $maps = $this->getMovementTotalsMaps();
        $prefix = "{$itemId}_{$officeId}_";
        $buckets = [];

        foreach ($this->positionKeysFromMaps($maps) as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $stock = $this->calculateStockFromMaps($key, $maps);
            if ($stock <= 0) {
                continue;
            }

            $parsed = UnitCostKey::parsePositionKey($key);
            if ($parsed === null) {
                continue;
            }

            $buckets[$parsed['unit_cost']] = $stock;
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Oldest unit-cost bucket with stock (FIFO default for issuance).
     */
    public function resolveFifoUnitCost(int $itemId, int $officeId): ?float
    {
        $buckets = $this->getUnitCostBucketsWithStock($itemId, $officeId);
        if ($buckets === []) {
            return null;
        }

        $costs = array_keys($buckets);

        return (float) $costs[0];
    }

    /**
     * Weighted average unit cost across on-hand buckets.
     */
    public function weightedAverageUnitCost(int $itemId, int $officeId): ?float
    {
        $buckets = $this->getUnitCostBucketsWithStock($itemId, $officeId);
        if ($buckets === []) {
            return null;
        }

        $totalQty = 0;
        $totalValue = 0.0;

        foreach ($buckets as $unitCost => $qty) {
            $totalQty += (int) $qty;
            $totalValue += (float) $unitCost * (int) $qty;
        }

        if ($totalQty <= 0) {
            return null;
        }

        return round($totalValue / $totalQty, 2);
    }

    public function totalStockValue(int $itemId, int $officeId): float
    {
        $buckets = $this->getUnitCostBucketsWithStock($itemId, $officeId);
        $total = 0.0;

        foreach ($buckets as $unitCost => $qty) {
            $total += (float) $unitCost * (int) $qty;
        }

        return round($total, 2);
    }

    /**
     * Most recent acquisition unit cost still present in stock buckets, else latest acquisition cost.
     */
    public function latestUnitCost(int $itemId, int $officeId): ?float
    {
        $buckets = $this->getUnitCostBucketsWithStock($itemId, $officeId);
        $bucketCosts = array_keys($buckets);

        $latestWithStock = DB::table('acquisitions')
            ->whereNull('deleted_at')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->when($bucketCosts !== [], fn ($q) => $q->whereIn(DB::raw('COALESCE(unit_cost, 0)'), $bucketCosts))
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($latestWithStock !== null) {
            return (float) $latestWithStock;
        }

        if ($bucketCosts !== []) {
            return (float) max($bucketCosts);
        }

        $latest = DB::table('acquisitions')
            ->whereNull('deleted_at')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->value('unit_cost');

        return $latest !== null ? (float) $latest : null;
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<string, ItemStockBucket>
     */
    protected function stockBucketsByItemCost(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $buckets = [];

        foreach (ItemStockBucket::query()->whereIn('item_id', $itemIds)->orderBy('id')->get() as $bucket) {
            $key = $bucket->item_id.'_'.UnitCostKey::normalize((float) $bucket->unit_cost);
            $buckets[$key] ??= $bucket;
        }

        return $buckets;
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<string, StockPositionRestockFlag>
     */
    protected function restockFlagsByPosition(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $flags = [];

        foreach (StockPositionRestockFlag::query()->whereIn('item_id', $itemIds)->orderBy('id')->get() as $flag) {
            $key = $flag->item_id.'_'.$flag->office_id.'_'.UnitCostKey::normalize((float) $flag->unit_cost);
            $flags[$key] ??= $flag;
        }

        return $flags;
    }

    /**
     * Latest acquisition unit cost per item/office, matching {@see latestUnitCost()}.
     *
     * @param  Collection<int, object>  $bucketRows
     * @return array<string, float|null>
     */
    protected function latestUnitCostsByItemOffice(Collection $bucketRows): array
    {
        $pairs = [];

        foreach ($bucketRows as $row) {
            $pairs[(int) $row->item_id.'_'.(int) $row->office_id] = true;
        }

        if ($pairs === []) {
            return [];
        }

        $itemIds = $bucketRows->pluck('item_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $acquisitionsByPair = [];

        foreach (DB::table('acquisitions')
            ->whereNull('deleted_at')
            ->whereIn('item_id', $itemIds)
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->get(['item_id', 'office_id', 'unit_cost']) as $acquisition) {
            $acquisitionsByPair[(int) $acquisition->item_id.'_'.(int) $acquisition->office_id][] = $acquisition->unit_cost;
        }

        $latest = [];

        foreach (array_keys($pairs) as $pairKey) {
            [$itemId, $officeId] = array_map('intval', explode('_', $pairKey, 2));
            $bucketCosts = [];

            foreach (array_keys($this->getUnitCostBucketsWithStock($itemId, $officeId)) as $cost) {
                $normalized = UnitCostKey::normalize((float) $cost);
                $bucketCosts[$normalized] = UnitCostKey::toFloat($normalized);
            }

            $costs = $acquisitionsByPair[$pairKey] ?? [];

            if ($bucketCosts === []) {
                $latestCost = $costs[0] ?? null;
                $latest[$pairKey] = $latestCost !== null ? (float) $latestCost : null;

                continue;
            }

            $matched = false;

            foreach ($costs as $cost) {
                $normalized = UnitCostKey::normalize($cost !== null ? (float) $cost : 0.0);

                if (! isset($bucketCosts[$normalized])) {
                    continue;
                }

                if ($cost !== null) {
                    $latest[$pairKey] = (float) $cost;
                    $matched = true;
                }

                break;
            }

            if (! $matched) {
                $latest[$pairKey] = max($bucketCosts);
            }
        }

        return $latest;
    }

    /**
     * Collapse per-cost bucket rows into one summary row per item/office (WAC display).
     *
     * @param  Collection<int, object>  $bucketRows
     * @return Collection<int, object>
     */
    public function summarizeStockLevelsByItemOffice(Collection $bucketRows, bool $includeLatestUnitCost = true): Collection
    {
        $latestUnitCosts = $includeLatestUnitCost
            ? $this->latestUnitCostsByItemOffice($bucketRows)
            : [];

        return $bucketRows
            ->groupBy(fn (object $row): string => (int) $row->item_id.'_'.(int) $row->office_id)
            ->map(function (Collection $group) use ($latestUnitCosts, $includeLatestUnitCost): object {
                /** @var object $first */
                $first = $group->first();
                $stock = (int) $group->sum('stock');
                $value = round($group->sum(fn (object $row): float => (float) ($row->unit_cost ?? 0) * (int) $row->stock), 2);
                $avg = $stock > 0 ? round($value / $stock, 2) : null;
                $itemId = (int) $first->item_id;
                $officeId = (int) $first->office_id;
                $pairKey = $itemId.'_'.$officeId;
                $latest = $includeLatestUnitCost
                    ? (($latestUnitCosts[$pairKey] ?? null) ?? $avg)
                    : $avg;
                $anyInactive = $group->contains(fn (object $row): bool => (bool) ($row->is_inactive_for_restock ?? false));
                $allInactive = $group->every(fn (object $row): bool => (bool) ($row->is_inactive_for_restock ?? false));

                return (object) [
                    'item_id' => $itemId,
                    'item_name' => $first->item_name,
                    'category_name' => $first->category_name,
                    'office_id' => $officeId,
                    'office_name' => $first->office_name,
                    'unit_cost' => $avg,
                    'avg_unit_cost' => $avg,
                    'latest_unit_cost' => $latest,
                    'stock_value' => $value,
                    'property_number' => $first->property_number ?? null,
                    'property_class' => $first->property_class ?? null,
                    'value_type' => SemiExpendableValueCategory::valueTypeForUnitCost($avg ?? 0),
                    'stock' => $stock,
                    'reorder_level' => (int) ($first->reorder_level ?? 0),
                    'tracks_reorder' => (bool) ($first->tracks_reorder ?? false),
                    'is_low' => (bool) ($first->tracks_reorder ?? false)
                        && (int) ($first->reorder_level ?? 0) > 0
                        && $stock <= (int) ($first->reorder_level ?? 0),
                    'is_inactive_for_restock' => $allInactive,
                    'inactive_source' => $allInactive ? ($first->inactive_source ?? null) : ($anyInactive ? 'mixed' : null),
                    'restock_status_label' => $allInactive
                        ? ($first->restock_status_label ?? 'Inactive')
                        : ($anyInactive ? 'Inactive' : 'Active'),
                    'position_key' => UnitCostKey::positionKey($itemId, $officeId, $avg),
                    'cost_bucket_count' => $group->count(),
                ];
            })
            ->values()
            ->sortBy(['item_name', 'office_name'])
            ->values();
    }

    /**
     * Latest acquisition unit costs for summarized stock rows (item × office).
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, float|null>
     */
    public function latestUnitCostsForStockRows(Collection $rows): array
    {
        return $this->latestUnitCostsByItemOffice($rows);
    }

    /**
     * @return array{
     *   opening: array<string, int>,
     *   acq: array<string, int>,
     *   inTransfers: array<string, int>,
     *   issuances: array<string, int>,
     *   outTransfers: array<string, int>,
     *   disposals: array<string, int>
     * }
     */
    protected function getMovementTotalsMaps(): array
    {
        return self::$movementTotalsMaps ??= [
            'opening' => $this->buildOpeningBalanceMap(),
            'acq' => $this->buildMovementMap('acquisitions', 'office_id', 'unit_cost'),
            'inTransfers' => $this->buildMovementMap('transfers', 'to_office_id', 'unit_cost'),
            'issuances' => $this->buildMovementMap('issuances', 'office_id', 'unit_cost'),
            'outTransfers' => $this->buildMovementMap('transfers', 'from_office_id', 'unit_cost', excludeReturns: true),
            'disposals' => $this->buildConfirmedDisposalMovementMap(),
        ];
    }

    /**
     * Local movement maps for a category/office scope. Does not touch the request-wide cache.
     *
     * @param  array<int, int>  $itemIds
     * @return array{
     *   opening: array<string, int>,
     *   acq: array<string, int>,
     *   inTransfers: array<string, int>,
     *   issuances: array<string, int>,
     *   outTransfers: array<string, int>,
     *   disposals: array<string, int>
     * }
     */
    protected function buildScopedMovementTotalsMaps(array $itemIds, ?int $officeId = null): array
    {
        return [
            'opening' => $this->buildOpeningBalanceMap($itemIds, $officeId),
            'acq' => $this->buildMovementMap('acquisitions', 'office_id', 'unit_cost', itemIds: $itemIds, officeId: $officeId),
            'inTransfers' => $this->buildMovementMap('transfers', 'to_office_id', 'unit_cost', itemIds: $itemIds, officeId: $officeId),
            'issuances' => $this->buildMovementMap('issuances', 'office_id', 'unit_cost', itemIds: $itemIds, officeId: $officeId),
            'outTransfers' => $this->buildMovementMap('transfers', 'from_office_id', 'unit_cost', excludeReturns: true, itemIds: $itemIds, officeId: $officeId),
            'disposals' => $this->buildConfirmedDisposalMovementMap($itemIds, $officeId),
        ];
    }

    /**
     * @param  array<int, int>|null  $itemIds
     * @return array<string, int>
     */
    protected function buildOpeningBalanceMap(?array $itemIds = null, ?int $officeId = null): array
    {
        return DB::table('stock_opening_balances')
            ->leftJoin(
                'stock_opening_balance_batches',
                'stock_opening_balances.batch_id',
                '=',
                'stock_opening_balance_batches.id',
            )
            ->where(function ($query): void {
                $query->whereNull('stock_opening_balances.batch_id')
                    ->orWhereNotNull('stock_opening_balance_batches.confirmed_at');
            })
            ->when($itemIds !== null, fn ($q) => $q->whereIn('stock_opening_balances.item_id', $itemIds))
            ->when($officeId !== null, fn ($q) => $q->where('stock_opening_balances.office_id', $officeId))
            ->select(
                'stock_opening_balances.item_id',
                'stock_opening_balances.office_id',
                DB::raw('COALESCE(stock_opening_balances.unit_cost, 0) as unit_cost'),
                DB::raw('SUM(stock_opening_balances.quantity) as total'),
            )
            ->groupBy(
                'stock_opening_balances.item_id',
                'stock_opening_balances.office_id',
                DB::raw('COALESCE(stock_opening_balances.unit_cost, 0)'),
            )
            ->get()
            ->mapWithKeys(function ($row): array {
                $key = UnitCostKey::positionKey(
                    (int) $row->item_id,
                    (int) $row->office_id,
                    (float) $row->unit_cost,
                );

                return [$key => (int) $row->total];
            })
            ->all();
    }

    /**
     * @param  array<int, int>|null  $itemIds
     * @return array<string, int>
     */
    protected function buildMovementMap(
        string $table,
        string $officeColumn,
        string $costColumn,
        bool $excludeReturns = false,
        ?array $itemIds = null,
        ?int $officeId = null,
    ): array {
        $query = DB::table($table)
            ->whereNull('deleted_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->when($officeId !== null, fn ($q) => $q->where($officeColumn, $officeId));

        if ($excludeReturns) {
            $query->where(function ($scope): void {
                $scope->whereNull('transfer_type')
                    ->orWhere('transfer_type', '!=', 'return');
            });
        }

        return $query
            ->select(
                'item_id',
                "{$officeColumn} as office_id",
                DB::raw("COALESCE({$costColumn}, 0) as unit_cost"),
                DB::raw('SUM(quantity) as total'),
            )
            ->groupBy('item_id', $officeColumn, DB::raw("COALESCE({$costColumn}, 0)"))
            ->get()
            ->mapWithKeys(function ($row): array {
                $key = UnitCostKey::positionKey(
                    (int) $row->item_id,
                    (int) $row->office_id,
                    (float) $row->unit_cost,
                );

                return [$key => (int) $row->total];
            })
            ->all();
    }

    /**
     * Only confirmed disposals reduce stock (draft disposals are excluded).
     *
     * @param  array<int, int>|null  $itemIds
     * @return array<string, int>
     */
    protected function buildConfirmedDisposalMovementMap(?array $itemIds = null, ?int $officeId = null): array
    {
        return DB::table('disposals')
            ->join('disposal_batches', 'disposals.disposal_batch_id', '=', 'disposal_batches.id')
            ->whereNull('disposals.deleted_at')
            ->whereNotNull('disposal_batches.confirmed_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('disposals.item_id', $itemIds))
            ->when($officeId !== null, fn ($q) => $q->where('disposals.office_id', $officeId))
            ->select(
                'disposals.item_id',
                'disposals.office_id',
                DB::raw('COALESCE(disposals.acquisition_cost, 0) as unit_cost'),
                DB::raw('SUM(disposals.quantity) as total'),
            )
            ->groupBy('disposals.item_id', 'disposals.office_id', DB::raw('COALESCE(disposals.acquisition_cost, 0)'))
            ->get()
            ->mapWithKeys(function ($row): array {
                $key = UnitCostKey::positionKey(
                    (int) $row->item_id,
                    (int) $row->office_id,
                    (float) $row->unit_cost,
                );

                return [$key => (int) $row->total];
            })
            ->all();
    }

    /**
     * @param  array{opening: array<string, int>, acq: array<string, int>, inTransfers: array<string, int>, issuances: array<string, int>, outTransfers: array<string, int>, disposals: array<string, int>}  $maps
     */
    protected function calculateStockFromMaps(string $key, array $maps): int
    {
        $stock = ($maps['opening'][$key] ?? 0)
            + ($maps['acq'][$key] ?? 0) + ($maps['inTransfers'][$key] ?? 0)
            - ($maps['issuances'][$key] ?? 0) - ($maps['outTransfers'][$key] ?? 0) - ($maps['disposals'][$key] ?? 0);

        return max(0, $stock);
    }

    /**
     * @param  array{opening: array<string, int>, acq: array<string, int>, inTransfers: array<string, int>, issuances: array<string, int>, outTransfers: array<string, int>, disposals: array<string, int>}  $maps
     * @return array<int, string>
     */
    protected function positionKeysFromMaps(array $maps): array
    {
        $keys = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
