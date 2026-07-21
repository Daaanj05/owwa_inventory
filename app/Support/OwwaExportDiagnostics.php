<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class OwwaExportDiagnostics
{
    public static function raiseMemoryLimit(string $target = '512M'): string
    {
        $before = (string) ini_get('memory_limit');
        $beforeBytes = self::memoryLimitBytes($before);
        $targetBytes = self::memoryLimitBytes($target);

        if ($beforeBytes < $targetBytes) {
            ini_set('memory_limit', $target);
        }

        $after = (string) ini_get('memory_limit');

        if (self::memoryLimitBytes($after) < $targetBytes) {
            self::appendDedicatedLog('memory_limit_raise_failed', [
                'before' => $before,
                'after' => $after,
                'target' => $target,
            ], toPhpErrorLog: true);
            Log::error('owwa_export: memory_limit_raise_failed', [
                'before' => $before,
                'after' => $after,
                'target' => $target,
            ]);
        }

        return $after;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $event, array $context = []): void
    {
        $payload = array_merge([
            'event' => $event,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ], $context);

        Log::warning('owwa_export: '.$event, $payload);
        self::appendDedicatedLog($event, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $event, Throwable $throwable, array $context = []): void
    {
        $payload = array_merge([
            'event' => $event,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile().':'.$throwable->getLine(),
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ], $context);

        Log::error('owwa_export: '.$event, $payload);
        self::appendDedicatedLog($event, $payload, toPhpErrorLog: true);
    }

    public static function registerOomGuard(string $path): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        register_shutdown_function(function () use ($path): void {
            $last = error_get_last();
            if ($last === null) {
                return;
            }

            $message = (string) ($last['message'] ?? '');
            if (! str_contains($message, 'Allowed memory size') && ! str_contains($message, 'Out of memory')) {
                return;
            }

            self::appendDedicatedLog('oom_shutdown', [
                'path' => $path,
                'php_error' => $last,
                'memory_limit' => ini_get('memory_limit'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ], toPhpErrorLog: true);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function appendDedicatedLog(string $event, array $payload, bool $toPhpErrorLog = false): void
    {
        $line = '['.date('Y-m-d H:i:s').'] '.$event.' '.json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = storage_path('logs/owwa-export.log');

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        if ($toPhpErrorLog) {
            @error_log('owwa_export '.$event.' '.json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
    }

    public static function memoryLimitBytes(string $limit): int
    {
        if ($limit === '-1' || $limit === '') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limit,
        };
    }
}
