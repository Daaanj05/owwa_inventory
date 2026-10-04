<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class StockCardExportStatusService
{
    public function cacheKey(int $userId): string
    {
        return 'stock-card-export-status:'.$userId;
    }

    public function markQueued(int $userId, string $format): void
    {
        Cache::put($this->cacheKey($userId), [
            'status' => 'queued',
            'format' => $format === 'pdf' ? 'pdf' : 'xlsx',
            'filename' => null,
            'download_url' => null,
            'preview_url' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    public function markReady(
        int $userId,
        string $format,
        string $filename,
        string $downloadUrl,
        ?string $previewUrl = null,
    ): void {
        Cache::put($this->cacheKey($userId), [
            'status' => 'ready',
            'format' => $format === 'pdf' ? 'pdf' : 'xlsx',
            'filename' => $filename,
            'download_url' => $downloadUrl,
            'preview_url' => $previewUrl,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    public function markFailed(int $userId, string $format): void
    {
        Cache::put($this->cacheKey($userId), [
            'status' => 'failed',
            'format' => $format === 'pdf' ? 'pdf' : 'xlsx',
            'filename' => null,
            'download_url' => null,
            'preview_url' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    /**
     * @return array{status: string, format: string|null, filename: string|null, download_url: string|null, preview_url: string|null, updated_at: string|null}|null
     */
    public function statusFor(int $userId): ?array
    {
        $status = Cache::get($this->cacheKey($userId));

        return is_array($status) ? $status : null;
    }
}
