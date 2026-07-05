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
        $pesosWords = ucfirst((string) ($formatter->format($pesos) ?: 'zero'));

        if ($centavos > 0) {
            $centavosWords = ucfirst((string) ($formatter->format($centavos) ?: 'zero'));

            return "{$pesosWords} Pesos and {$centavosWords} Centavos Only";
        }

        return "{$pesosWords} Pesos Only";
    }
}
