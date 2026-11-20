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
     * @return float Slope per period.
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

        if ($first == 0.0) {
            return null;
        }

        return round((($last - $first) / $first) * 100, 1);
    }

    /**
     * Share of total usage held by the single largest month when that month is a spike.
     * Spike if max/sum >= 0.50 or max >= 3 × median of positive months. Null if not a spike.
     *
     * @param  array<int|float>  $monthlyValues  Ordered by time (oldest first).
     */
    public static function usageSpikeShare(array $monthlyValues): ?float
    {
        $values = array_map(static fn ($v): float => (float) $v, array_values($monthlyValues));
        $sum = array_sum($values);
        if ($sum <= 0.0) {
            return null;
        }

        $positive = array_values(array_filter($values, static fn (float $v): bool => $v > 0.0));
        if ($positive === []) {
            return null;
        }

        $max = max($positive);
        $share = $max / $sum;

        sort($positive);
        $count = count($positive);
        $mid = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $positive[$mid]
            : (($positive[$mid - 1] + $positive[$mid]) / 2.0);

        $isSpike = $share >= 0.50 || ($median > 0.0 && $max >= (3.0 * $median));

        return $isSpike ? round($share, 4) : null;
    }
}
