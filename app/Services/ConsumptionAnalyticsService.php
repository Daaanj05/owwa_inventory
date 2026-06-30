<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Issuance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConsumptionAnalyticsService
{
    /**
     * Get consumption (issuance quantity) by department and time period for charting.
     * Returns labels (e.g. month names), and one series per department.
     *
     * @param  array<int>  $departmentIds  Empty = all departments.
     * @param  array<int>  $officeIds  Empty = all offices.
     * @param  bool  $includeYearInLabels  When true (multi-year view), chart labels use a compact year format.
     * @return array{labels: array<string>, series: array<string, array<int>>, departments: array<int, string>}
     */
    public function getConsumptionByDepartmentAndPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        array $departmentIds = [],
        array $officeIds = [],
        bool $includeYearInLabels = false
    ): array {
        $periods = $this->buildPeriods($from, $to, $includeYearInLabels);
        $labels = $periods->map(fn ($p) => $p['label'])->values()->all();

        $query = Issuance::query()
            ->whereBetween('issuance_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('department_id');

        if ($departmentIds !== []) {
            $query->whereIn('department_id', $departmentIds);
        }

        if ($officeIds !== []) {
            $query->whereIn('office_id', $officeIds);
        }

        $departmentIdsUsed = (clone $query)->distinct()->pluck('department_id')->filter()->values();
        $departments = Department::whereIn('id', $departmentIdsUsed)->pluck('name', 'id')->all();

        $series = [];
        foreach (array_keys($departments) as $deptId) {
            $series[(string) $deptId] = array_fill(0, count($periods), 0);
        }

        $periodKeys = $periods->pluck('key')->all();
        $issuances = (clone $query)->get(['department_id', 'issuance_date', 'quantity']);

        foreach ($issuances as $row) {
            $period = Carbon::parse($row->issuance_date)->format('Y-m');
            $idx = array_search($period, $periodKeys, true);
            if ($idx !== false && isset($series[(string) $row->department_id])) {
                $series[(string) $row->department_id][$idx] += (int) $row->quantity;
            }
        }

        $departmentNames = $departments;
        $outSeries = [];
        foreach ($series as $deptId => $values) {
            $outSeries[$departmentNames[(int) $deptId] ?? 'Department #'.$deptId] = $values;
        }

        return [
            'labels' => $labels,
            'series' => $outSeries,
            'departments' => $departmentNames,
        ];
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
        bool $includeYearInLabels = false
    ): array {
        $data = $this->getConsumptionByDepartmentAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels);

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
        bool $includeYearInLabels = false
    ): array {
        $data = $this->getConsumptionByDepartmentAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearInLabels);

        $labels = [];
        $values = [];
        $total = 0;

        foreach ($data['series'] as $deptName => $periodValues) {
            $sum = array_sum($periodValues);
            if ($sum > 0) {
                $labels[] = $deptName;
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
}
