<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class DashboardKpiCache
{
    public const VERSION_KEY = 'dashboard.kpi_counts.version';

    public const TTL_SECONDS = 60;

    public static function bump(): void
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);
        Cache::forever(self::VERSION_KEY, $version + 1);
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $suffix, callable $callback): mixed
    {
        $key = 'dashboard.kpi_counts.v'.self::version().'.'.$suffix;

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }
}
