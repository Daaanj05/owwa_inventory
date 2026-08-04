<?php

namespace App\Support;

class UserLogDisplay
{
    public static function browserLabel(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return '—';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Edg/') => 'Google Chrome',
            str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome/') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os !== null ? "{$browser} on {$os}" : $browser;
    }

    public static function pathLabel(?string $path, ?string $panel = null): string
    {
        $path = trim((string) $path, '/');

        if ($path === '' || self::isLivewireUpdatePath($path)) {
            return self::panelLandingLabel($panel);
        }

        $normalized = strtolower($path);

        return match (true) {
            $normalized === 'system-admin/login', $normalized === 'admin/login' => self::panelLandingLabel($panel, 'Login'),
            $normalized === 'system-admin', $normalized === 'admin' => self::panelLandingLabel($panel, 'Dashboard'),
            str_starts_with($normalized, 'system-admin/') => 'System Admin — '.self::humanizeSegment(substr($path, strlen('system-admin/'))),
            str_starts_with($normalized, 'admin/') => 'Admin — '.self::humanizeSegment(substr($path, strlen('admin/'))),
            default => self::humanizeSegment($path),
        };
    }

    public static function resolveLoginPath(?string $requestPath, ?string $referer = null, ?string $panelId = null): string
    {
        $requestPath = trim((string) $requestPath, '/');

        if ($requestPath !== '' && ! self::isLivewireUpdatePath($requestPath)) {
            return $requestPath;
        }

        if (filled($referer)) {
            $refererPath = parse_url((string) $referer, PHP_URL_PATH);
            $refererPath = is_string($refererPath) ? trim($refererPath, '/') : '';

            if ($refererPath !== '' && ! self::isLivewireUpdatePath($refererPath)) {
                return $refererPath;
            }
        }

        return match ($panelId) {
            'system-admin' => 'system-admin/login',
            'admin' => 'admin/login',
            default => $requestPath !== '' ? $requestPath : 'login',
        };
    }

    public static function isLivewireUpdatePath(string $path): bool
    {
        $path = trim($path, '/');

        return (bool) preg_match('#^livewire[^/]*/update$#i', $path)
            || str_starts_with(strtolower($path), 'livewire/');
    }

    protected static function panelLandingLabel(?string $panel, string $suffix = 'panel'): string
    {
        $panelLabel = match ($panel) {
            'system-admin' => 'System Admin',
            'admin' => 'Admin',
            default => filled($panel) ? self::humanizeSegment((string) $panel) : 'App',
        };

        return "{$panelLabel} — {$suffix}";
    }

    protected static function humanizeSegment(string $path): string
    {
        $segment = str_replace(['-', '_'], ' ', $path);
        $segment = preg_replace('#/+#', ' / ', $segment) ?? $segment;

        return ucwords(trim($segment));
    }
}
