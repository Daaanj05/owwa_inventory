<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProcurementDecisionSupportService
{
    private const FORECAST_LOOKBACK_MONTHS = 12;

    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    /**
     * Build at-risk procurement rows based on consumption trend (forecast) + current stock.
     *
     * Forecast approach:
     * - build monthly series of issued quantities per (item, office)
     * - compute last-N moving average
     * - add a simple linear trend slope adjustment
     *
     * Suggested reorder:
     * - reorder to reach targetCoverMonths of forecasted monthly usage
     *
     * @return Collection<int, object{
     *   item_id:int,
     *   office_id:int,
     *   item_name:string,
     *   office_name:string,
     *   current_stock:int,
     *   reorder_level:int,
     *   forecast_monthly_usage:float,
     *   months_cover:float|null,
     *   suggested_reorder_qty:int|null,
     *   projected_stockout_date:string,
     *   latest_unit_cost:float|null,
     *   item_category_id:int,
     *   has_recent_usage:bool,
     *   priority:string
     * }>
     */
    public function getAtRiskRows(
        Carbon $from,
        Carbon $to,
        ?int $categoryId = null,
        array $officeIds = [],
        int $movingAverageMonths = 6,
        int $forecastHorizonMonths = 3,
        int $targetCoverMonths = 3,
        int $limit = 12,
    ): Collection {
        $to = min($to->copy()->endOfMonth(), now()->endOfMonth());

        $forecastFrom = $to->copy()->subMonths(self::FORECAST_LOOKBACK_MONTHS - 1)->startOfMonth();

        $issuances = Issuance::query()
            ->whereBetween('issuance_date', [$forecastFrom->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->select([
                'item_id',
                'office_id',
                $this->monthYearSqlExpression('issuance_date'),
                DB::raw('SUM(quantity) as total_qty'),
            ])
            ->groupBy('item_id', 'office_id', 'ym')
            ->get();

        $periodKeys = $this->buildMonthKeys($forecastFrom, $to);
        $effectiveMovingAverageMonths = min($movingAverageMonths, max(1, count($periodKeys)));

        $itemIds = $issuances->pluck('item_id')->unique()->values()->all();
        $items = Item::query()
            ->when($categoryId !== null, fn ($q) => $q->where('item_category_id', $categoryId))
            ->whereIn('id', $itemIds)
            ->get(['id', 'name', 'reorder_level', 'item_category_id'])
            ->keyBy('id');

        $officeIdsUsed = $issuances->pluck('office_id')->unique()->values()->all();
        $offices = Office::query()
            ->when($officeIds !== [], fn ($q) => $q->whereIn('id', $officeIds))
            ->whereIn('id', $officeIdsUsed)
            ->get(['id', 'name'])
            ->keyBy('id');

        $series = [];
        foreach ($issuances as $row) {
            $key = "{$row->item_id}_{$row->office_id}";
            $series[$key] ??= array_fill(0, count($periodKeys), 0.0);
            $idx = array_search($row->ym, $periodKeys, true);
            if ($idx !== false) {
                $series[$key][$idx] = (float) $row->total_qty;
            }
        }

        $latestUnitCosts = $this->getLatestUnitCosts($itemIds);

        $rows = collect();
        $seenKeys = [];

        foreach ($series as $key => $values) {
            [$itemId, $officeId] = array_map('intval', explode('_', $key, 2));

            $item = $items->get($itemId);
            $office = $offices->get($officeId);
            if (! $item || ! $office) {
                continue;
            }

            $forecastMonthly = $this->forecastMonthlyUsage($values, $effectiveMovingAverageMonths, $forecastHorizonMonths);
            if ($forecastMonthly <= 0) {
                continue;
            }

            $currentStock = $this->stockService->getStock($itemId, $officeId);
            $monthsCover = $currentStock > 0 ? ($currentStock / $forecastMonthly) : 0.0;
            $suggested = $this->resolveSuggestedReorderQty(
                currentStock: $currentStock,
                reorderLevel: (int) $item->reorder_level,
                forecastMonthly: $forecastMonthly,
                targetCoverMonths: $targetCoverMonths,
            );

            $priority = $this->priorityFor($monthsCover, (int) $item->reorder_level, $currentStock);

            if ($priority === 'Low') {
                continue;
            }

            $stockoutDate = $monthsCover > 0
                ? now()->addDays((int) round($monthsCover * 30))->toDateString()
                : now()->toDateString();

            $seenKeys[$key] = true;

            $rows->push($this->makeAtRiskRow(
                item: $item,
                office: $office,
                currentStock: $currentStock,
                forecastMonthly: $forecastMonthly,
                monthsCover: $monthsCover,
                suggested: $suggested,
                stockoutDate: $stockoutDate,
                latestUnitCosts: $latestUnitCosts,
                hasRecentUsage: true,
                priority: $priority,
            ));
        }

        $rows = $this->appendLowStockWithoutRecentUsage(
            $rows,
            $seenKeys,
            $categoryId,
            $officeIds,
            $latestUnitCosts,
        );

        return $rows
            ->sortBy([
                fn ($r) => match ($r->priority) {
                    'High' => 0,
                    'Medium' => 1,
                    default => 2,
                },
                fn ($r) => $r->months_cover ?? 999,
            ])
            ->values()
            ->take($limit);
    }

    /**
     * Coverage summary for the current scope (only pairs with measurable forecast usage).
     *
     * @return array{
     *   pairs:int,
     *   avg_months_cover:float,
     *   median_months_cover:float,
     *   high_risk:int,
     *   medium_risk:int,
     *   generated_at:string
     * }
     */
    public function getCoverageSummary(
        Carbon $from,
        Carbon $to,
        ?int $categoryId = null,
        array $officeIds = [],
    ): array {
        $rows = $this->getAtRiskRows(
            from: $from,
            to: $to,
            categoryId: $categoryId,
            officeIds: $officeIds,
            movingAverageMonths: 6,
            forecastHorizonMonths: 3,
            targetCoverMonths: 3,
            limit: 5000,
        );

        $pairs = $rows->count();
        if ($pairs === 0) {
            return [
                'pairs' => 0,
                'avg_months_cover' => 0.0,
                'median_months_cover' => 0.0,
                'high_risk' => 0,
                'medium_risk' => 0,
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $covers = $rows->pluck('months_cover')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->sort()->values();
        $avg = round($covers->avg() ?? 0.0, 2);
        $median = round($covers->median() ?? 0.0, 2);

        return [
            'pairs' => $pairs,
            'avg_months_cover' => $avg,
            'median_months_cover' => $median,
            'high_risk' => $rows->where('priority', 'High')->count(),
            'medium_risk' => $rows->where('priority', 'Medium')->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, object{item_name:string, office_name:string, months_cover:float, projected_stockout_date:string, priority:string}>
     */
    public function getProjectedStockouts(
        Carbon $from,
        Carbon $to,
        ?int $categoryId = null,
        array $officeIds = [],
        int $withinMonths = 2,
        int $limit = 10,
    ): Collection {
        $rows = $this->getAtRiskRows(
            from: $from,
            to: $to,
            categoryId: $categoryId,
            officeIds: $officeIds,
            movingAverageMonths: 6,
            forecastHorizonMonths: 3,
            targetCoverMonths: 3,
            limit: 5000,
        );

        $cutoff = (float) max(0.1, $withinMonths);

        return $rows
            ->filter(fn ($r) => $r->months_cover !== null && (float) $r->months_cover <= $cutoff)
            ->sortBy(fn ($r) => (float) $r->months_cover)
            ->take($limit)
            ->values()
            ->map(function ($r) {
                $months = (float) ($r->months_cover ?? 0);
                $date = now()->addDays((int) round($months * 30));

                return (object) [
                    'item_name' => $r->item_name,
                    'office_name' => $r->office_name,
                    'months_cover' => (float) $months,
                    'projected_stockout_date' => $date->toDateString(),
                    'priority' => $r->priority,
                ];
            });
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<int, float> item_id => latest unit cost
     */
    protected function getLatestUnitCosts(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return Acquisition::query()
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->select('item_id', 'unit_cost')
            ->orderByDesc('acquisition_date')
            ->get()
            ->unique('item_id')
            ->pluck('unit_cost', 'item_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    protected function monthYearSqlExpression(string $column): \Illuminate\Contracts\Database\Query\Expression
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => DB::raw("strftime('%Y-%m', {$column}) as ym"),
            'pgsql' => DB::raw("to_char({$column}, 'YYYY-MM') as ym"),
            default => DB::raw("DATE_FORMAT({$column}, '%Y-%m') as ym"),
        };
    }

    /** @return array<int, string> */
    protected function buildMonthKeys(Carbon $from, Carbon $to): array
    {
        $keys = [];
        $current = $from->copy()->startOfMonth();
        while ($current->lte($to)) {
            $keys[] = $current->format('Y-m');
            $current->addMonth();
        }

        return $keys;
    }

    /**
     * @param  array<int, float>  $monthlyValues
     */
    protected function forecastMonthlyUsage(array $monthlyValues, int $movingAverageMonths, int $horizonMonths): float
    {
        $values = array_values($monthlyValues);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        $tail = array_slice($values, max(0, $n - $movingAverageMonths));
        $avg = count($tail) > 0 ? (array_sum($tail) / count($tail)) : 0.0;

        $slope = InventoryAlgorithms::linearTrendSlope($values);
        $adjusted = $avg + ($slope * min(max($horizonMonths, 1), 6));

        return max(0.0, (float) $adjusted);
    }

    /**
     * @param  array<string, true>  $seenKeys
     * @param  array<int, float>  $latestUnitCosts
     */
    protected function appendLowStockWithoutRecentUsage(
        Collection $rows,
        array $seenKeys,
        ?int $categoryId,
        array $officeIds,
        array $latestUnitCosts,
    ): Collection {
        if ($officeIds === []) {
            return $rows;
        }

        $items = Item::query()
            ->active()
            ->where('reorder_level', '>', 0)
            ->when($categoryId !== null, fn ($q) => $q->where('item_category_id', $categoryId))
            ->get(['id', 'name', 'reorder_level', 'item_category_id']);

        $offices = Office::query()
            ->active()
            ->whereIn('id', $officeIds)
            ->get(['id', 'name']);

        foreach ($offices as $office) {
            foreach ($items as $item) {
                $key = "{$item->id}_{$office->id}";
                if (isset($seenKeys[$key])) {
                    continue;
                }

                $currentStock = $this->stockService->getStock((int) $item->id, (int) $office->id);
                if ($currentStock >= (int) $item->reorder_level) {
                    continue;
                }

                $suggested = $this->resolveSuggestedReorderQty(
                    currentStock: $currentStock,
                    reorderLevel: (int) $item->reorder_level,
                    forecastMonthly: 0.0,
                    targetCoverMonths: 3,
                );

                $rows->push($this->makeAtRiskRow(
                    item: $item,
                    office: $office,
                    currentStock: $currentStock,
                    forecastMonthly: 0.0,
                    monthsCover: null,
                    suggested: $suggested,
                    stockoutDate: now()->toDateString(),
                    latestUnitCosts: $latestUnitCosts,
                    hasRecentUsage: false,
                    priority: 'High',
                ));
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, float>  $latestUnitCosts
     */
    protected function makeAtRiskRow(
        Item $item,
        Office $office,
        int $currentStock,
        float $forecastMonthly,
        ?float $monthsCover,
        ?int $suggested,
        string $stockoutDate,
        array $latestUnitCosts,
        bool $hasRecentUsage,
        string $priority,
    ): object {
        return (object) [
            'item_id' => (int) $item->id,
            'office_id' => (int) $office->id,
            'item_name' => (string) $item->name,
            'office_name' => (string) $office->name,
            'item_category_id' => (int) $item->item_category_id,
            'current_stock' => $currentStock,
            'reorder_level' => (int) $item->reorder_level,
            'forecast_monthly_usage' => round($forecastMonthly, 2),
            'months_cover' => $monthsCover !== null ? round($monthsCover, 2) : null,
            'suggested_reorder_qty' => $suggested,
            'projected_stockout_date' => $stockoutDate,
            'latest_unit_cost' => $latestUnitCosts[(int) $item->id] ?? null,
            'has_recent_usage' => $hasRecentUsage,
            'priority' => $priority,
        ];
    }

    protected function resolveSuggestedReorderQty(
        int $currentStock,
        int $reorderLevel,
        float $forecastMonthly,
        int $targetCoverMonths,
    ): ?int {
        $forecastBased = $forecastMonthly > 0
            ? (int) max(0, ceil(($targetCoverMonths * $forecastMonthly) - $currentStock))
            : 0;

        $reorderFloor = ($reorderLevel > 0 && $currentStock < $reorderLevel)
            ? max(1, $reorderLevel - $currentStock)
            : 0;

        $suggested = max($forecastBased, $reorderFloor);

        return $suggested > 0 ? $suggested : null;
    }

    protected function priorityFor(float $monthsCover, int $reorderLevel, int $currentStock): string
    {
        if ($reorderLevel > 0 && $currentStock < $reorderLevel) {
            return 'High';
        }

        if ($monthsCover < 1) {
            return 'High';
        }

        if ($monthsCover <= 3) {
            return 'Medium';
        }

        return 'Low';
    }
}
