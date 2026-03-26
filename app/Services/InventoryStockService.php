<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Office;
use Illuminate\Support\Facades\DB;

class InventoryStockService
{
    /**
     * Get current stock quantity for an item at an office.
     * Stock = sum(acquisitions) + sum(transfers in) - sum(issuances) - sum(transfers out) - sum(disposals)
     */
    public function getStock(int $itemId, int $officeId): int
    {
        $acq = DB::table('acquisitions')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');

        $inTransfers = DB::table('transfers')
            ->where('item_id', $itemId)
            ->where('to_office_id', $officeId)
            ->sum('quantity');

        $issuances = DB::table('issuances')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');

        $outTransfers = DB::table('transfers')
            ->where('item_id', $itemId)
            ->where('from_office_id', $officeId)
            ->sum('quantity');

        $disposals = DB::table('disposals')
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');

        $stock = (int) ($acq + $inTransfers - $issuances - $outTransfers - $disposals);

        // Clamp at zero so we never expose negative stock in the UI or AI
        // context. Negative would only mean historical issuances exceeded
        // recorded acquisitions, which is a data quality issue.
        return max(0, $stock);
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
        $stock = $this->getStock($item->id, $officeId);
        return $stock <= $item->reorder_level && $item->reorder_level > 0;
    }

    /**
     * Count of (item, office) pairs where current stock is at or below reorder point.
     * Uses batched queries to avoid timeout (was O(items × offices × 5) queries).
     *
     * @param  array<int>|null  $officeIds  When provided, only count low stock for these offices (e.g. Unit Head/Employee scope).
     * @param  int|null  $fiscalYearId  When provided, only consider items and offices for this fiscal year (active only).
     */
    public function lowStockCount(?array $officeIds = null, ?int $fiscalYearId = null): int
    {
        $acq = DB::table('acquisitions')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $inTransfers = DB::table('transfers')
            ->select('item_id', 'to_office_id as office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'to_office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $issuances = DB::table('issuances')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $outTransfers = DB::table('transfers')
            ->select('item_id', 'from_office_id as office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'from_office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $disposals = DB::table('disposals')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $itemOfficesQuery = DB::table('items')
            ->where('items.reorder_level', '>', 0)
            ->crossJoin('offices')
            ->select('items.id as item_id', 'items.reorder_level', 'offices.id as office_id');

        if ($fiscalYearId !== null) {
            $itemOfficesQuery->where('items.fiscal_year_id', $fiscalYearId)
                ->whereNull('items.archived_at')
                ->where('offices.fiscal_year_id', $fiscalYearId)
                ->whereNull('offices.archived_at');
        }

        if ($officeIds !== null && $officeIds !== []) {
            $itemOfficesQuery->whereIn('offices.id', $officeIds);
        }

        $itemOffices = $itemOfficesQuery->get();

        $count = 0;
        foreach ($itemOffices as $row) {
            $key = "{$row->item_id}_{$row->office_id}";
            $stock = ($acq[$key] ?? 0) + ($inTransfers[$key] ?? 0)
                - ($issuances[$key] ?? 0) - ($outTransfers[$key] ?? 0) - ($disposals[$key] ?? 0);
            $stock = max(0, $stock);
            if ($stock <= (int) $row->reorder_level) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get full list of item–office stock levels for display (e.g. Stock levels page).
     *
     * @return \Illuminate\Support\Collection<int, object{item_id: int, item_name: string, category_name: string, office_id: int, office_name: string, stock: int, reorder_level: int, is_low: bool}>
     */
    public function getStockLevelsList(): \Illuminate\Support\Collection
    {
        $acq = DB::table('acquisitions')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $inTransfers = DB::table('transfers')
            ->select('item_id', 'to_office_id as office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'to_office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $issuances = DB::table('issuances')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $outTransfers = DB::table('transfers')
            ->select('item_id', 'from_office_id as office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'from_office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $disposals = DB::table('disposals')
            ->select('item_id', 'office_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('item_id', 'office_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_id}_{$r->office_id}" => (int) $r->total]);

        $rows = DB::table('items')
            ->join('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->crossJoin('offices')
            ->select(
                'items.id as item_id',
                'items.name as item_name',
                'item_categories.name as category_name',
                'offices.id as office_id',
                'offices.name as office_name',
                'items.reorder_level'
            )
            ->orderBy('items.name')
            ->orderBy('offices.name')
            ->get();

        return $rows->map(function ($row) use ($acq, $inTransfers, $issuances, $outTransfers, $disposals) {
            $key = "{$row->item_id}_{$row->office_id}";
            $stock = ($acq[$key] ?? 0) + ($inTransfers[$key] ?? 0)
                - ($issuances[$key] ?? 0) - ($outTransfers[$key] ?? 0) - ($disposals[$key] ?? 0);
            $stock = max(0, $stock);
            $reorderLevel = (int) $row->reorder_level;
            $isLow = $reorderLevel > 0 && $stock <= $reorderLevel;

            return (object) [
                'item_id' => (int) $row->item_id,
                'item_name' => $row->item_name,
                'category_name' => $row->category_name,
                'office_id' => (int) $row->office_id,
                'office_name' => $row->office_name,
                'stock' => $stock,
                'reorder_level' => $reorderLevel,
                'is_low' => $isLow,
            ];
        });
    }
}
