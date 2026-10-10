<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TemporaryPassword
{
    public static function generate(): string
    {
        $upper = strtoupper(Str::random(1));
        $lower = strtolower(Str::random(3));
        $digits = (string) random_int(1000, 9999);
        $symbols = '!@#$%&*';
        $symbol = $symbols[random_int(0, strlen($symbols) - 1)];
        $tail = Str::random(4);

        return str_shuffle($upper.$lower.$digits.$symbol.$tail);
    }
}
