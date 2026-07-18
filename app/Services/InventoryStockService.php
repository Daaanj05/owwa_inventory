<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemStockBucket;
use App\Models\StockPositionRestockFlag;
use App\Support\SemiExpendableValueCategory;
use App\Support\UnitCostKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryStockService
{
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
        if (! $this->hasInventoryActivity($item->id, $officeId)) {
            return false;
        }

        $stock = $this->getStock($item->id, $officeId);

        return $stock < $item->reorder_level && $item->reorder_level > 0;
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
     * @return array<string, true>
     */
    public function getActiveStockPositionKeys(): array
    {
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

        $addKeys(DB::table('acquisitions')->whereNull('deleted_at')->select('item_id', 'office_id', 'unit_cost')->distinct()->get(), 'office_id');
        $addKeys(DB::table('issuances')->whereNull('deleted_at')->select('item_id', 'office_id', 'unit_cost')->distinct()->get(), 'office_id');
        $addKeys(DB::table('disposals')->whereNull('deleted_at')->select('item_id', 'office_id', 'acquisition_cost as unit_cost')->distinct()->get(), 'office_id');
        $addKeys(
            DB::table('transfers')->whereNull('deleted_at')->select('item_id', 'from_office_id as office_id', 'unit_cost')->distinct()->get(),
            'office_id',
        );
        $addKeys(
            DB::table('transfers')->whereNull('deleted_at')->select('item_id', 'to_office_id as office_id', 'unit_cost')->distinct()->get(),
            'office_id',
        );

        return $keys;
    }

    public function hasInventoryActivity(int $itemId, int $officeId): bool
    {
        return isset($this->getActiveItemOfficePairKeys()["{$itemId}_{$officeId}"]);
    }

    public function lowStockCount(?array $officeIds = null, ?int $fiscalYearId = null): int
    {
        unset($fiscalYearId);

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

            if ($this->getStock($itemId, $officeId) < (int) $items[$itemId]) {
                $count++;
            }
        }

        return $count;
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
    public function getStockLevelsList(?int $categoryId = null): Collection
    {
        $positionKeys = array_keys($this->getActiveStockPositionKeys());
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

        $offices = DB::table('offices')
            ->whereIn('id', array_keys($officeIds))
            ->whereNull('archived_at')
            ->pluck('name', 'id');

        $maps = $this->getMovementTotalsMaps();
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

            $bucket = ItemStockBucket::findForItemCost($position['item_id'], $position['unit_cost']);
            $flag = StockPositionRestockFlag::findForPosition(
                $position['item_id'],
                $position['office_id'],
                $position['unit_cost'],
            );

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
                $row->is_low = $row->reorder_level > 0 && $aggregate < $row->reorder_level;

                return $row;
            })
            ->sortBy(['item_name', 'office_name', 'unit_cost'])
            ->values();
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
     * @return array{
     *   acq: array<string, int>,
     *   inTransfers: array<string, int>,
     *   issuances: array<string, int>,
     *   outTransfers: array<string, int>,
     *   disposals: array<string, int>
     * }
     */
    protected function getMovementTotalsMaps(): array
    {
        return [
            'acq' => $this->buildMovementMap('acquisitions', 'office_id', 'unit_cost'),
            'inTransfers' => $this->buildMovementMap('transfers', 'to_office_id', 'unit_cost'),
            'issuances' => $this->buildMovementMap('issuances', 'office_id', 'unit_cost'),
            'outTransfers' => $this->buildMovementMap('transfers', 'from_office_id', 'unit_cost'),
            'disposals' => $this->buildMovementMap('disposals', 'office_id', 'acquisition_cost'),
        ];
    }

    protected function buildMovementMap(string $table, string $officeColumn, string $costColumn): array
    {
        return DB::table($table)
            ->whereNull('deleted_at')
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
     * @param  array{acq: array<string, int>, inTransfers: array<string, int>, issuances: array<string, int>, outTransfers: array<string, int>, disposals: array<string, int>}  $maps
     */
    protected function calculateStockFromMaps(string $key, array $maps): int
    {
        $stock = ($maps['acq'][$key] ?? 0) + ($maps['inTransfers'][$key] ?? 0)
            - ($maps['issuances'][$key] ?? 0) - ($maps['outTransfers'][$key] ?? 0) - ($maps['disposals'][$key] ?? 0);

        return max(0, $stock);
    }

    /**
     * @param  array{acq: array<string, int>, inTransfers: array<string, int>, issuances: array<string, int>, outTransfers: array<string, int>, disposals: array<string, int>}  $maps
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
