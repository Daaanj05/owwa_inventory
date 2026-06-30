<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryStockService
{
    /**
     * Get current stock quantity for an item at an office.
     * Stock = sum(acquisitions) + sum(transfers in) - sum(issuances) - sum(transfers out) - sum(disposals)
     */
    public function getStock(int $itemId, int $officeId): int
    {
        $maps = $this->getMovementTotalsMaps();
        $key = "{$itemId}_{$officeId}";

        return $this->calculateStockFromMaps($key, $maps);
    }

    /**
     * Get stock for all items at an office.
     *
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
     * Item×office pairs that have ever had inventory movement (acquisition, issuance, transfer, disposal).
     *
     * @return array<string, true>
     */
    public function getActiveItemOfficePairKeys(): array
    {
        $keys = [];

        $addPairs = function (Collection $rows) use (&$keys): void {
            foreach ($rows as $row) {
                $keys["{$row->item_id}_{$row->office_id}"] = true;
            }
        };

        $addPairs(DB::table('acquisitions')->select('item_id', 'office_id')->distinct()->get());
        $addPairs(DB::table('issuances')->select('item_id', 'office_id')->distinct()->get());
        $addPairs(DB::table('disposals')->select('item_id', 'office_id')->distinct()->get());
        $addPairs(DB::table('transfers')->select('item_id', 'from_office_id as office_id')->distinct()->get());
        $addPairs(DB::table('transfers')->select('item_id', 'to_office_id as office_id')->distinct()->get());

        return $keys;
    }

    public function hasInventoryActivity(int $itemId, int $officeId): bool
    {
        if (DB::table('acquisitions')->where('item_id', $itemId)->where('office_id', $officeId)->exists()) {
            return true;
        }

        if (DB::table('issuances')->where('item_id', $itemId)->where('office_id', $officeId)->exists()) {
            return true;
        }

        if (DB::table('disposals')->where('item_id', $itemId)->where('office_id', $officeId)->exists()) {
            return true;
        }

        return DB::table('transfers')
            ->where('item_id', $itemId)
            ->where(fn ($q) => $q->where('from_office_id', $officeId)->orWhere('to_office_id', $officeId))
            ->exists();
    }

    /**
     * Count of (item, office) pairs where current stock is at or below reorder point.
     * Only includes pairs with inventory activity (not catalog-only).
     *
     * @param  array<int>|null  $officeIds  When provided, only count low stock for these offices.
     * @param  int|null  $fiscalYearId  Unused after fiscal year scoping removal; kept for call-site compatibility.
     */
    public function lowStockCount(?array $officeIds = null, ?int $fiscalYearId = null): int
    {
        unset($fiscalYearId);

        $maps = $this->getMovementTotalsMaps();
        $activeKeys = $this->getActiveItemOfficePairKeys();

        $itemIds = [];
        $officeIdsFromKeys = [];
        foreach (array_keys($activeKeys) as $key) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));
            if ($officeIds !== null && $officeIds !== [] && ! in_array($officeId, $officeIds, true)) {
                continue;
            }
            $itemIds[$itemId] = true;
            $officeIdsFromKeys[$officeId] = true;
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
        foreach (array_keys($activeKeys) as $key) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));
            if ($officeIds !== null && $officeIds !== [] && ! in_array($officeId, $officeIds, true)) {
                continue;
            }
            if (! isset($items[$itemId])) {
                continue;
            }

            $stock = $this->calculateStockFromMaps($key, $maps);
            if ($stock < (int) $items[$itemId]) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Stock positions with inventory history at each office (excludes catalog-only item×office pairs).
     *
     * @return Collection<int, object{item_id: int, item_name: string, category_name: string, office_id: int, office_name: string, property_class: ?string, value_type: ?string, stock: int, reorder_level: int, is_low: bool}>
     */
    public function getStockLevelsList(?int $categoryId = null): Collection
    {
        $activeKeys = array_keys($this->getActiveItemOfficePairKeys());
        if ($activeKeys === []) {
            return collect();
        }

        $itemIds = [];
        $officeIds = [];
        foreach ($activeKeys as $key) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));
            $itemIds[$itemId] = true;
            $officeIds[$officeId] = true;
        }

        $query = DB::table('items')
            ->join('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->join('offices', function ($join) use ($officeIds): void {
                $join->whereIn('offices.id', array_keys($officeIds))
                    ->whereNull('offices.archived_at');
            })
            ->whereIn('items.id', array_keys($itemIds))
            ->whereNull('items.archived_at')
            ->when($categoryId !== null, fn ($q) => $q->where('items.item_category_id', $categoryId))
            ->select(
                'items.id as item_id',
                'items.name as item_name',
                'item_categories.name as category_name',
                'offices.id as office_id',
                'offices.name as office_name',
                'items.reorder_level',
                'items.property_class as property_class',
                'items.value_type as value_type',
            )
            ->orderBy('items.name')
            ->orderBy('offices.name');

        $maps = $this->getMovementTotalsMaps();

        return $query->get()
            ->filter(function ($row) use ($activeKeys): bool {
                $key = "{$row->item_id}_{$row->office_id}";

                return in_array($key, $activeKeys, true);
            })
            ->map(function ($row) use ($maps) {
                $key = "{$row->item_id}_{$row->office_id}";
                $stock = $this->calculateStockFromMaps($key, $maps);
                $reorderLevel = (int) $row->reorder_level;
                $isLow = $reorderLevel > 0 && $stock < $reorderLevel;

                return (object) [
                    'item_id' => (int) $row->item_id,
                    'item_name' => $row->item_name,
                    'category_name' => $row->category_name,
                    'office_id' => (int) $row->office_id,
                    'office_name' => $row->office_name,
                    'property_class' => $row->property_class,
                    'value_type' => $row->value_type,
                    'stock' => $stock,
                    'reorder_level' => $reorderLevel,
                    'is_low' => $isLow,
                ];
            })
            ->values();
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
            'acq' => DB::table('acquisitions')
                ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('item_id', 'office_id')
                ->get()
                ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total])
                ->all(),
            'inTransfers' => DB::table('transfers')
                ->select('item_id', 'to_office_id as office_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('item_id', 'to_office_id')
                ->get()
                ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total])
                ->all(),
            'issuances' => DB::table('issuances')
                ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('item_id', 'office_id')
                ->get()
                ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total])
                ->all(),
            'outTransfers' => DB::table('transfers')
                ->select('item_id', 'from_office_id as office_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('item_id', 'from_office_id')
                ->get()
                ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total])
                ->all(),
            'disposals' => DB::table('disposals')
                ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('item_id', 'office_id')
                ->get()
                ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total])
                ->all(),
        ];
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
}
