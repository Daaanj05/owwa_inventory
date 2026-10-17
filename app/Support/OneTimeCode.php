<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

final class OneTimeCode
{
    public static function generate(int $digits = 6): string
    {
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    public static function hash(string $code): string
    {
        return Hash::make($code);
    }

    public static function matches(string $plainCode, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return Hash::check($plainCode, $hash);
    }
}
