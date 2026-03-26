<?php

namespace App\Services;

/**
 * Inventory algorithms: moving average, consumption rate, and simple trend.
 * Used for analytics and reorder/forecasting logic.
 */
class InventoryAlgorithms
{
    /**
     * Simple moving average (SMA) over the last N periods.
     * Earlier positions are filled with null so chart can show partial average or omit.
     *
     * @param  array<int|float>  $values
     * @return array<int|float|null>
     */
    public static function movingAverage(array $values, int $periods): array
    {
        if ($periods < 1 || count($values) < $periods) {
            return $values;
        }

        $result = [];
        for ($i = 0; $i < count($values); $i++) {
            if ($i < $periods - 1) {
                $result[] = null;
                continue;
            }
            $slice = array_slice($values, $i - $periods + 1, $periods);
            $result[] = array_sum($slice) / $periods;
        }

        return $result;
    }

    /**
     * Consumption rate: total quantity per period (e.g. units per month).
     */
    public static function consumptionRate(float $totalQuantity, int $numberOfPeriods): float
    {
        if ($numberOfPeriods <= 0) {
            return 0.0;
        }

        return round($totalQuantity / $numberOfPeriods, 2);
    }

    /**
     * Simple linear trend slope (units per period) via least squares.
     * Positive = increasing consumption, negative = decreasing.
     *
     * @param  array<int|float>  $values  Ordered by time (oldest first).
     * @return float  Slope per period.
     */
    public static function linearTrendSlope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = $i;
            $y = $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        if (abs($denom) < 1e-10) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denom;
    }

    /**
     * Period-over-period growth (percent change) from first to last value.
     *
     * @param  array<int|float>  $values
     */
    public static function periodOverPeriodGrowth(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $first = (float) $values[0];
        $last = (float) $values[count($values) - 1];

        if ($first == 0) {
            return $last > 0 ? 100.0 : null;
        }

        return round((($last - $first) / $first) * 100, 1);
    }
}
