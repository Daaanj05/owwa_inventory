<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class AiProcurementSummaryRestore
{
    public static function cacheKey(int $userId): string
    {
        return "ai-procurement-pending-summary:{$userId}";
    }

    public static function shownKey(int $userId, int $runId): string
    {
        return "ai-procurement-summary-shown:{$userId}:{$runId}";
    }

    public static function hasBeenShown(int $userId, int $runId): bool
    {
        return Cache::has(self::shownKey($userId, $runId));
    }

    public static function markShown(int $userId, int $runId): void
    {
        Cache::put(self::shownKey($userId, $runId), true, now()->addDays(7));
    }

    public static function remember(int $userId, int $runId): void
    {
        if (self::hasBeenShown($userId, $runId)) {
            return;
        }

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

    /**
     * @param  array{title: string, body: string, danger?: bool, seconds?: int, actionLabel?: string, actionUrl?: string}  $detail
     */
    public static function browserAnnounceScript(array $detail): string
    {
        return 'window.owwaAnnounceAiRecommendationDone('.json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).')';
    }
}
