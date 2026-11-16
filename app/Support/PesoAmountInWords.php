<?php

namespace App\Support;

class PesoAmountInWords
{
    public static function format(float $amount): string
    {
        $amount = round($amount, 2);
        $pesos = (int) floor($amount);
        $centavos = (int) round(($amount - $pesos) * 100);

        $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
        $pesosWords = self::titleCase((string) ($formatter->format($pesos) ?: 'zero'));

        if ($centavos > 0) {
            $centavosWords = self::titleCase((string) ($formatter->format($centavos) ?: 'zero'));

            return "{$pesosWords} Pesos And {$centavosWords} Centavos Only";
        }

        return "{$pesosWords} Pesos Only";
    }

    /**
     * Capitalize each word, including segments after hyphens (e.g. Twenty-Three).
     */
    protected static function titleCase(string $words): string
    {
        return ucwords(strtolower(trim($words)), " \t\r\n\f\v-");
    }
}
