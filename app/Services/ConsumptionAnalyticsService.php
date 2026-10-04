<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Support\InventoryCategoryOptions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConsumptionAnalyticsService
{
    /**
     * Get consumption (issuance quantity) by department and time period for charting.
     * Returns labels (e.g. month names), and one series per department.
     *
     * @param  array<int>  $departmentIds  Empty = all departments.
     * @param  array<int>  $officeIds  Empty = all offices.
     * @param  bool  $includeYearInLabels  When true (multi-year view), chart labels use a compact year format.
     * @param  array<int>  $itemIds  Empty = all items.
     * @return array{labels: array<string>, series: array<string, array<int>>, departments: array<int, string>}
     */
    public function getConsumptionByDepartmentAndPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $cacheKey = $this->consumptionCacheKey(
            'department',
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
        );

        if ($this->shouldCacheConsumptionResult($departmentIds, $officeIds, $itemIds)) {
            /** @var array{labels: array<string>, series: array<string, array<int>>, departments: array<int, string>} */
            return Cache::remember(
                $cacheKey,
                60,
                fn (): array => $this->computeConsumptionByDimensionAndPeriod(
                    'department_id',
                    $this->resolveChartDepartments($departmentIds, $officeIds),
                    $from,
                    $to,
                    $departmentIds,
                    $officeIds,
                    $includeYearInLabels,
                    $itemIds,
                    'departments',
                ),
            );
        }

        return $this->computeConsumptionByDimensionAndPeriod(
            'department_id',
            $this->resolveChartDepartments($departmentIds, $officeIds),
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
            'departments',
        );
    }

    /**
     * Get consumption (issuance quantity) by office and time period for charting.
     * Returns labels (e.g. month names), and one series per office.
     *
     * @param  array<int>  $departmentIds  Empty = all departments (optional scoping filter).
     * @param  array<int>  $officeIds  Empty = all offices.
     * @param  bool  $includeYearInLabels  When true (multi-year view), chart labels use a compact year format.
     * @param  array<int>  $itemIds  Empty = all items.
     * @return array{labels: array<string>, series: array<string, array<int>>, offices: array<int, string>}
     */
    public function getConsumptionByOfficeAndPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $cacheKey = $this->consumptionCacheKey(
            'office',
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
        );

        if ($this->shouldCacheConsumptionResult($departmentIds, $officeIds, $itemIds)) {
            /** @var array{labels: array<string>, series: array<string, array<int>>, offices: array<int, string>} */
            return Cache::remember(
                $cacheKey,
                60,
                fn (): array => $this->computeConsumptionByDimensionAndPeriod(
                    'office_id',
                    $this->resolveChartOffices($officeIds),
                    $from,
                    $to,
                    $departmentIds,
                    $officeIds,
                    $includeYearInLabels,
                    $itemIds,
                    'offices',
                ),
            );
        }

        return $this->computeConsumptionByDimensionAndPeriod(
            'office_id',
            $this->resolveChartOffices($officeIds),
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
            'offices',
        );
    }

    /**
     * Get consumption (issuance quantity) by item and time period for charting.
     * Returns labels (e.g. month names), and one series per item.
     *
     * @param  array<int>  $departmentIds  Empty = all departments (optional scoping filter).
     * @param  array<int>  $officeIds  Empty = all offices.
     * @param  bool  $includeYearInLabels  When true (multi-year view), chart labels use a compact year format.
     * @param  array<int>  $itemIds  Empty = all items.
     * @return array{labels: array<string>, series: array<string, array<int>>, items: array<int, string>}
     */
    public function getConsumptionByItemAndPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $periods = $this->buildPeriods($from, $to, $includeYearInLabels);
        $labels = $periods->map(fn ($p) => $p['label'])->values()->all();

        $query = Issuance::query()
            ->whereBetween('issuance_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('item_id');

        $this->applyScopeFilters($query, $departmentIds, $officeIds, $itemIds);

        $itemIdsUsed = (clone $query)->distinct()->pluck('item_id')->filter()->values();
        $items = Item::query()
            ->whereIn('id', $itemIdsUsed)
            ->get(['id', 'name', 'sub_item'])
            ->mapWithKeys(fn (Item $item): array => [
                $item->id => $this->itemChartLabel($item),
            ])
            ->all();

        $series = [];
        foreach (array_keys($items) as $itemId) {
            $series[(string) $itemId] = array_fill(0, count($periods), 0);
        }

        $periodKeys = $periods->pluck('key')->all();
        $issuances = (clone $query)->get(['item_id', 'issuance_date', 'quantity']);

        foreach ($issuances as $row) {
            $period = Carbon::parse($row->issuance_date)->format('Y-m');
            $idx = array_search($period, $periodKeys, true);
            if ($idx !== false && isset($series[(string) $row->item_id])) {
                $series[(string) $row->item_id][$idx] += (int) $row->quantity;
            }
        }

        $outSeries = [];
        foreach ($series as $itemId => $values) {
            $outSeries[$items[(int) $itemId] ?? 'Item #'.$itemId] = $values;
        }

        return [
            'labels' => $labels,
            'series' => $outSeries,
            'items' => $items,
        ];
    }

    /**
     * Consumption by office×item for chart legends that show both names.
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds  Required for meaningful dual labels; empty returns no series.
     * @return array{labels: array<string>, series: array<string, array<int>>}
     */
    public function getConsumptionByOfficeAndItemAndPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $periods = $this->buildPeriods($from, $to, $includeYearInLabels);
        $labels = $periods->map(fn ($p) => $p['label'])->values()->all();

        if ($itemIds === []) {
            return [
                'labels' => $labels,
                'series' => [],
            ];
        }

        $query = Issuance::query()
            ->whereBetween('issuance_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('office_id')
            ->whereNotNull('item_id');

        $this->applyScopeFilters($query, $departmentIds, $officeIds, $itemIds);

        $pairs = (clone $query)
            ->select('office_id', 'item_id')
            ->distinct()
            ->get();

        $officeNames = Office::query()
            ->whereIn('id', $pairs->pluck('office_id')->unique()->filter()->all())
            ->pluck('name', 'id')
            ->all();

        $itemLabels = Item::query()
            ->whereIn('id', $pairs->pluck('item_id')->unique()->filter()->all())
            ->get(['id', 'name', 'sub_item'])
            ->mapWithKeys(fn (Item $item): array => [
                $item->id => $this->itemChartLabel($item),
            ])
            ->all();

        $series = [];
        foreach ($pairs as $pair) {
            $key = (int) $pair->office_id.'_'.(int) $pair->item_id;
            $series[$key] = array_fill(0, count($periods), 0);
        }

        $periodKeys = $periods->pluck('key')->all();
        $issuances = (clone $query)->get(['office_id', 'item_id', 'issuance_date', 'quantity']);

        foreach ($issuances as $row) {
            $key = (int) $row->office_id.'_'.(int) $row->item_id;
            $period = Carbon::parse($row->issuance_date)->format('Y-m');
            $idx = array_search($period, $periodKeys, true);
            if ($idx !== false && isset($series[$key])) {
                $series[$key][$idx] += (int) $row->quantity;
            }
        }

        $outSeries = [];
        foreach ($series as $key => $values) {
            [$officeId, $itemId] = array_map('intval', explode('_', $key, 2));
            $officeLabel = $officeNames[$officeId] ?? 'Office #'.$officeId;
            $itemLabel = $itemLabels[$itemId] ?? 'Item #'.$itemId;
            $outSeries[$officeLabel.' — '.$itemLabel] = $values;
        }

        return [
            'labels' => $labels,
            'series' => $outSeries,
        ];
    }

    /**
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     * @return array{labels: array<string>, values: array<int>, total: int}
     */
    public function getConsumptionTotalsByOfficeAndItem(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByOfficeAndItemAndPeriod(
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
        );

        $labels = [];
        $values = [];
        $total = 0;

        foreach ($data['series'] as $name => $periodValues) {
            $sum = array_sum($periodValues);
            if ($sum > 0) {
                $labels[] = $name;
                $values[] = $sum;
                $total += $sum;
            }
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     * @param  array<int, string>  $dimensionNames  id => name
     * @return array{labels: array<string>, series: array<string, array<int>>, departments?: array<int, string>, offices?: array<int, string>}
     */
    protected function computeConsumptionByDimensionAndPeriod(
        string $dimensionColumn,
        array $dimensionNames,
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds,
        array $officeIds,
        bool $includeYearInLabels,
        array $itemIds,
        string $namesKey,
    ): array {
        $periods = $this->buildPeriods($from, $to, $includeYearInLabels);
        $labels = $periods->map(fn ($p) => $p['label'])->values()->all();
        $periodKeys = $periods->pluck('key')->all();
        $periodIndex = array_flip($periodKeys);

        $series = [];
        foreach (array_keys($dimensionNames) as $dimensionId) {
            $series[(string) $dimensionId] = array_fill(0, count($periods), 0);
        }

        $query = Issuance::query()
            ->whereBetween('issuance_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull($dimensionColumn);

        $this->applyScopeFilters($query, $departmentIds, $officeIds, $itemIds);

        $periodSql = $this->monthYearSql($column = 'issuance_date');

        $rows = (clone $query)
            ->selectRaw("{$dimensionColumn}, {$periodSql} as ym, SUM(quantity) as total_quantity")
            ->groupByRaw("{$dimensionColumn}, {$periodSql}")
            ->get();

        foreach ($rows as $row) {
            $dimensionId = (string) $row->{$dimensionColumn};
            $period = (string) $row->ym;
            if (! isset($series[$dimensionId], $periodIndex[$period])) {
                continue;
            }

            $series[$dimensionId][$periodIndex[$period]] += (int) $row->total_quantity;
        }

        $outSeries = [];
        $fallbackPrefix = $namesKey === 'offices' ? 'Office #' : 'Department #';
        foreach ($series as $dimensionId => $values) {
            $outSeries[$dimensionNames[(int) $dimensionId] ?? $fallbackPrefix.$dimensionId] = $values;
        }

        return [
            'labels' => $labels,
            'series' => $outSeries,
            $namesKey => $dimensionNames,
        ];
    }

    /**
     * Cache only the default dashboard filter (all offices/departments/items).
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     */
    protected function shouldCacheConsumptionResult(array $departmentIds, array $officeIds, array $itemIds): bool
    {
        if ($this->isIsolatedSqliteTestDatabase()) {
            return false;
        }

        return $departmentIds === [] && $officeIds === [] && $itemIds === [];
    }

    /**
     * PHPUnit in this project forces sqlite :memory: even when APP_ENV stays local.
     */
    protected function isIsolatedSqliteTestDatabase(): bool
    {
        return config('database.default') === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:';
    }

    /**
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     */
    protected function consumptionCacheKey(
        string $dimension,
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds,
        array $officeIds,
        bool $includeYearInLabels,
        array $itemIds,
    ): string {
        return 'consumption.analytics.'.$dimension.'.'.md5(json_encode([
            $from->toDateString(),
            $to->toDateString(),
            $departmentIds,
            $officeIds,
            $includeYearInLabels,
            $itemIds,
        ], JSON_THROW_ON_ERROR));
    }

    protected function monthYearSql(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Offices to always plot for office-scoped charts.
     * Empty $officeIds = all active offices (zeros included when no issuances).
     *
     * @param  array<int>  $officeIds
     * @return array<int, string> id => name
     */
    protected function resolveChartOffices(array $officeIds): array
    {
        $query = Office::query()->active()->orderBy('name');

        if ($officeIds !== []) {
            $query->whereIn('id', $officeIds);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Departments to always plot for department-scoped charts.
     * Empty $departmentIds = active departments in the office scope (zeros included).
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @return array<int, string> id => name
     */
    protected function resolveChartDepartments(array $departmentIds, array $officeIds): array
    {
        $query = Department::query()->active()->orderBy('name');

        if ($departmentIds !== []) {
            $query->whereIn('id', $departmentIds);
        }

        if ($officeIds !== []) {
            $query->whereIn('office_id', $officeIds);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * @param  Builder<\App\Models\Issuance>  $query
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     */
    protected function applyScopeFilters(Builder $query, array $departmentIds, array $officeIds, array $itemIds): void
    {
        $query->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery
            ->whereIn('item_category_id', InventoryCategoryOptions::procurementAnalyticsCategoryIds()));

        if ($departmentIds !== []) {
            $query->whereIn('department_id', $departmentIds);
        }

        if ($officeIds !== []) {
            $query->whereIn('office_id', $officeIds);
        }

        if ($itemIds !== []) {
            $query->whereIn('item_id', $itemIds);
        }
    }

    /**
     * Build period buckets (monthly) between from and to.
     *
     * @return Collection<int, array{key: string, label: string}>
     */
    protected function buildPeriods(CarbonInterface $from, CarbonInterface $to, bool $includeYearInLabels = false): Collection
    {
        $periods = collect();
        $current = $from->copy()->startOfMonth();

        while ($current->lte($to)) {
            $label = $includeYearInLabels
                ? $current->format("M 'y")
                : $current->format('M Y');
            $periods->push([
                'key' => $current->format('Y-m'),
                'label' => $label,
            ]);
            $current->addMonth();
        }

        return $periods;
    }

    /**
     * Get summary stats for the period: total consumption, top department, avg per period.
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @return array{total: int, top_department_name: string|null, top_department_quantity: int, periods_count: int, avg_per_period: float, growth_percent: float|null, trend_slope: float}
     */
    public function getConsumptionSummary(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByDepartmentAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $total = 0;
        $topName = null;
        $topQty = 0;
        $totalsPerPeriod = array_fill(0, count($data['labels']), 0);

        foreach ($data['series'] as $deptName => $values) {
            $sum = array_sum($values);
            $total += $sum;
            if ($sum > $topQty) {
                $topQty = $sum;
                $topName = $deptName;
            }
            foreach ($values as $i => $v) {
                $totalsPerPeriod[$i] = ($totalsPerPeriod[$i] ?? 0) + (int) $v;
            }
        }

        $periodsCount = count($data['labels']);
        $avgPerPeriod = $periodsCount > 0 ? round($total / $periodsCount, 2) : 0.0;
        $growth = InventoryAlgorithms::periodOverPeriodGrowth($totalsPerPeriod);
        $slope = InventoryAlgorithms::linearTrendSlope($totalsPerPeriod);

        return [
            'total' => $total,
            'top_department_name' => $topName,
            'top_department_quantity' => $topQty,
            'periods_count' => $periodsCount,
            'avg_per_period' => $avgPerPeriod,
            'growth_percent' => $growth,
            'trend_slope' => round($slope, 3),
        ];
    }

    /**
     * Get total consumption per department in the period (for pie chart: share of total).
     *
     * @param  array<int>  $departmentIds  Empty = all departments.
     * @param  array<int>  $officeIds  Empty = all offices.
     * @return array{labels: array<string>, values: array<int>, total: int}
     */
    public function getConsumptionTotalsByDepartment(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByDepartmentAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $labels = [];
        $values = [];
        $total = 0;

        foreach ($data['series'] as $deptName => $periodValues) {
            $sum = array_sum($periodValues);
            $labels[] = $deptName;
            $values[] = $sum;
            $total += $sum;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
        ];
    }

    /**
     * Get summary stats for the period grouped by office: total consumption, top office, avg per period.
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @return array{total: int, top_office_name: string|null, top_office_quantity: int, periods_count: int, avg_per_period: float, growth_percent: float|null, trend_slope: float}
     */
    public function getConsumptionSummaryByOffice(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByOfficeAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $total = 0;
        $topName = null;
        $topQty = 0;
        $totalsPerPeriod = array_fill(0, count($data['labels']), 0);

        foreach ($data['series'] as $officeName => $values) {
            $sum = array_sum($values);
            $total += $sum;
            if ($sum > $topQty) {
                $topQty = $sum;
                $topName = $officeName;
            }
            foreach ($values as $i => $v) {
                $totalsPerPeriod[$i] = ($totalsPerPeriod[$i] ?? 0) + (int) $v;
            }
        }

        $periodsCount = count($data['labels']);
        $avgPerPeriod = $periodsCount > 0 ? round($total / $periodsCount, 2) : 0.0;
        $growth = InventoryAlgorithms::periodOverPeriodGrowth($totalsPerPeriod);
        $slope = InventoryAlgorithms::linearTrendSlope($totalsPerPeriod);

        return [
            'total' => $total,
            'top_office_name' => $topName,
            'top_office_quantity' => $topQty,
            'periods_count' => $periodsCount,
            'avg_per_period' => $avgPerPeriod,
            'growth_percent' => $growth,
            'trend_slope' => round($slope, 3),
        ];
    }

    /**
     * Get total consumption per office in the period (for pie chart: share of total).
     *
     * @param  array<int>  $departmentIds  Empty = all departments (optional scoping filter).
     * @param  array<int>  $officeIds  Empty = all offices.
     * @return array{labels: array<string>, values: array<int>, total: int}
     */
    public function getConsumptionTotalsByOffice(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByOfficeAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $labels = [];
        $values = [];
        $total = 0;

        foreach ($data['series'] as $officeName => $periodValues) {
            $sum = array_sum($periodValues);
            $labels[] = $officeName;
            $values[] = $sum;
            $total += $sum;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
        ];
    }

    /**
     * Get summary stats for the period grouped by item: total consumption, top item, avg per period.
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     * @return array{total: int, top_item_name: string|null, top_item_quantity: int, periods_count: int, avg_per_period: float, growth_percent: float|null, trend_slope: float}
     */
    public function getConsumptionSummaryByItem(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByItemAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $total = 0;
        $topName = null;
        $topQty = 0;
        $totalsPerPeriod = array_fill(0, count($data['labels']), 0);

        foreach ($data['series'] as $itemName => $values) {
            $sum = array_sum($values);
            $total += $sum;
            if ($sum > $topQty) {
                $topQty = $sum;
                $topName = $itemName;
            }
            foreach ($values as $i => $v) {
                $totalsPerPeriod[$i] = ($totalsPerPeriod[$i] ?? 0) + (int) $v;
            }
        }

        $periodsCount = count($data['labels']);
        $avgPerPeriod = $periodsCount > 0 ? round($total / $periodsCount, 2) : 0.0;
        $growth = InventoryAlgorithms::periodOverPeriodGrowth($totalsPerPeriod);
        $slope = InventoryAlgorithms::linearTrendSlope($totalsPerPeriod);

        return [
            'total' => $total,
            'top_item_name' => $topName,
            'top_item_quantity' => $topQty,
            'periods_count' => $periodsCount,
            'avg_per_period' => $avgPerPeriod,
            'growth_percent' => $growth,
            'trend_slope' => round($slope, 3),
        ];
    }

    /**
     * Get total consumption per item in the period (for pie chart: share of total).
     *
     * @param  array<int>  $departmentIds
     * @param  array<int>  $officeIds
     * @param  array<int>  $itemIds
     * @return array{labels: array<string>, values: array<int>, total: int}
     */
    public function getConsumptionTotalsByItem(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false,
        array $itemIds = [],
    ): array {
        $data = $this->getConsumptionByItemAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels, $itemIds);

        $labels = [];
        $values = [];
        $total = 0;

        foreach ($data['series'] as $itemName => $periodValues) {
            $sum = array_sum($periodValues);
            if ($sum > 0) {
                $labels[] = $itemName;
                $values[] = $sum;
                $total += $sum;
            }
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
        ];
    }

    /**
     * Apply moving average to each department series (for chart overlay or second dataset).
     *
     * @param  array<string, array<int>>  $series
     * @return array<string, array<int|float|null>>
     */
    public function applyMovingAverageToSeries(array $series, int $periods): array
    {
        $out = [];
        foreach ($series as $name => $values) {
            $out[$name] = InventoryAlgorithms::movingAverage($values, $periods);
        }

        return $out;
    }

    protected function itemChartLabel(Item $item): string
    {
        if (filled($item->sub_item)) {
            return (string) $item->sub_item.' — '.$item->name;
        }

        return (string) $item->name;
    }
}
