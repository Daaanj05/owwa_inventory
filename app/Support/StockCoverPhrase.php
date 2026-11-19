<?php

namespace App\Support;

class StockCoverPhrase
{
    /**
     * Turn months-of-cover into a plain days/weeks/months phrase (no decimals).
     */
    public static function fromMonths(float $months): string
    {
        if ($months <= 0) {
            return 'less than 1 day';
        }

        $days = (int) max(1, (int) round($months * 30));

        if ($days < 14) {
            return $days === 1 ? '1 day' : "{$days} days";
        }

        $weeks = (int) max(1, (int) round($days / 7));

        if ($weeks < 9) {
            return $weeks === 1 ? '1 week' : "{$weeks} weeks";
        }

        $wholeMonths = (int) max(1, (int) round($months));

        return $wholeMonths === 1 ? '1 month' : "{$wholeMonths} months";
    }

    /**
     * Replace decimal (or fractional) month cover wording in narrative prose.
     */
    public static function rewriteDecimalMonthsInText(string $text): string
    {
        $rewritten = preg_replace_callback(
            '/(\d+(?:\.\d+)?)\s*months?(?:\s+of\s+cover)?/iu',
            static function (array $matches): string {
                $phrase = self::fromMonths((float) $matches[1]);

                return str_contains(strtolower($matches[0]), 'cover')
                    ? $phrase.' of cover'
                    : $phrase;
            },
            $text,
        );

        return is_string($rewritten) ? $rewritten : $text;
    }
}
