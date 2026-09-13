<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class AiProcurementSummaryRestore
{
    public static function cacheKey(int $userId): string
    {
        return "ai-procurement-pending-summary:{$userId}";
    }

    public static function remember(int $userId, int $runId): void
    {
        Cache::put(self::cacheKey($userId), $runId, now()->addDay());
    }

    public static function pull(int $userId): ?int
    {
        $runId = Cache::pull(self::cacheKey($userId));

        return is_numeric($runId) ? (int) $runId : null;
    }

    public static function forget(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    /**
     * Ensure only one browser toast is shown per run (page sync + global chip both watch completion).
     */
    public static function claimSessionToast(int $runId): bool
    {
        return Cache::add("ai-procurement-session-toast:{$runId}", true, now()->addDay());
    }
}
