<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

final class LoginRememberedEmail
{
    public const string COOKIE = 'owwa_remembered_login_email';

    private const int MINUTES = 60 * 24 * 30;

    public static function read(): ?string
    {
        $email = request()->cookie(self::COOKIE);

        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function remember(string $email): void
    {
        $email = Str::lower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::forget();

            return;
        }

        Cookie::queue(cookie(
            name: self::COOKIE,
            value: $email,
            minutes: self::MINUTES,
            path: '/',
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: config('session.same_site', 'lax'),
        ));
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
