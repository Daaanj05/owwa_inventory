<?php

namespace App\Support;

final class OwwaExportDownloadCookie
{
    public const TOKEN_QUERY = 'owwa_download_token';

    public const DONE_COOKIE = 'owwa_export_done';

    public const PENDING_COOKIE = 'owwa_export_pending';

    public static function tokenFromRequest(?string $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $token = trim($token);

        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
            return null;
        }

        return $token;
    }

    /**
     * Build a same-origin relative download URL so Livewire redirects stay on the
     * host/port the user is actually browsing (e.g. :8080 vs APP_URL :8443).
     */
    public static function sameOriginDownloadUrl(string $url, ?string $token = null): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = [];

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $token ??= self::makeToken();
        $query[self::TOKEN_QUERY] = $token;

        $queryString = http_build_query($query);

        return $queryString === '' ? $path : $path.'?'.$queryString;
    }

    public static function makeToken(): string
    {
        return 'owwa'.bin2hex(random_bytes(12));
    }
}
