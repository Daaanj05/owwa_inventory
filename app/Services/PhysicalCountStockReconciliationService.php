<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\ItemCategory;

class PhysicalCountStockReconciliationService
{
    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    /**
     * @return array{
     *     office_id: int,
     *     item_category_id: int,
     *     accountable_unit_count: int,
     *     warehouse_unit_count: int,
     *     ledger_stock_total: int,
     *     drift: int,
     *     unit_status_breakdown: array<string, int>,
     *     items: array<int, array{item_id: int, item_name: string, stock: int, accountable_tags: int, drift: int}>
     * }
     */
    public function reconcile(int $officeId, int $itemCategoryId): array
    {
        $category = ItemCategory::query()->findOrFail($itemCategoryId);
        $categorySlug = $category->getTemplateSlug();

        $rows = $this->stockService->getStockLevelsList($itemCategoryId)
            ->where('office_id', $officeId)
            ->values();

        $taggedByKey = $this->accountableUnitCounts($officeId, $itemCategoryId);

        $items = [];
        $ledgerTotal = 0;

        foreach ($rows as $row) {
            $key = "{$row->item_id}_{$row->office_id}";
            $accountable = (int) ($taggedByKey[$key] ?? 0);
            $stock = (int) $row->stock;
            $ledgerTotal += $stock;

            if ($accountable < $stock) {
                $items[] = [
                    'item_id' => (int) $row->item_id,
                    'item_name' => (string) $row->item_name,
                    'stock' => $stock,
                    'accountable_tags' => $accountable,
                    'drift' => $accountable - $stock,
                ];
            }
        }

        $warehouseUnitCount = $this->warehouseUnitCount($officeId, $itemCategoryId, $categorySlug);
        $accountableUnitCount = $this->accountableUnitCount($officeId, $itemCategoryId, $categorySlug);

        return [
            'office_id' => $officeId,
            'item_category_id' => $itemCategoryId,
            'accountable_unit_count' => $accountableUnitCount,
            'warehouse_unit_count' => $warehouseUnitCount,
            'ledger_stock_total' => $ledgerTotal,
            'drift' => $warehouseUnitCount - $ledgerTotal,
            'unit_status_breakdown' => $this->unitStatusBreakdown($officeId, $itemCategoryId),
            'items' => $items,
        ];
    }

    protected function accountableUnitCount(int $officeId, int $itemCategoryId, string $categorySlug): int
    {
        return $this->countUnits($officeId, $itemCategoryId, $categorySlug, InventoryUnit::accountableStatuses());
    }

    protected function warehouseUnitCount(int $officeId, int $itemCategoryId, string $categorySlug): int
    {
        return $this->countUnits($officeId, $itemCategoryId, $categorySlug, [InventoryUnit::STATUS_IN_STOCK]);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected function countUnits(int $officeId, int $itemCategoryId, string $categorySlug, array $statuses): int
    {
        return InventoryUnit::query()
            ->where('office_id', $officeId)
            ->whereIn('status', $statuses)
            ->whereHas('item', function ($query) use ($itemCategoryId): void {
                $query->active()->where('item_category_id', $itemCategoryId);
            })
            ->whereHas('item.category', fn ($query) => $query->whereNull('archived_at'))
            ->get()
            ->filter(fn (InventoryUnit $unit): bool => $unit->item?->category?->getTemplateSlug() === $categorySlug)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    protected function accountableUnitCounts(int $officeId, int $itemCategoryId): array
    {
        $counts = InventoryUnit::query()
            ->selectRaw('item_id, office_id, count(*) as accountable_tags')
            ->where('office_id', $officeId)
            ->whereIn('status', InventoryUnit::accountableStatuses())
            ->whereHas('item', fn ($query) => $query->active()->where('item_category_id', $itemCategoryId))
            ->groupBy('item_id', 'office_id')
            ->get();

        $result = [];
        foreach ($counts as $row) {
            $result["{$row->item_id}_{$row->office_id}"] = (int) $row->accountable_tags;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    protected function unitStatusBreakdown(int $officeId, int $itemCategoryId): array
    {
        $rows = InventoryUnit::query()
            ->selectRaw('status, count(*) as total')
            ->where('office_id', $officeId)
            ->whereHas('item', fn ($query) => $query->active()->where('item_category_id', $itemCategoryId))
            ->groupBy('status')
            ->pluck('total', 'status');

        return $rows->map(fn ($count): int => (int) $count)->all();
    }
}
