<?php

namespace App\Support;

class StockCoverPhrase
{
    /**
     * Turn months-of-cover into a plain days/weeks/months/years phrase (no decimals).
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

        if ($months < 1) {
            $weeks = (int) max(1, (int) round($days / 7));

            return $weeks === 1 ? '1 week' : "{$weeks} weeks";
        }

        if ($months >= 12) {
            $years = (int) floor($months / 12);
            $remMonths = (int) round($months - ($years * 12));

            $yearPart = $years === 1 ? '1 year' : "{$years} years";
            if ($remMonths <= 0) {
                return $yearPart;
            }

            $monthPart = $remMonths === 1 ? '1 month' : "{$remMonths} months";

            return "{$yearPart} and {$monthPart}";
        }

        $wholeMonths = (int) floor($months);
        $remainderDays = (int) max(0, (int) round(($months - $wholeMonths) * 30));
        $remWeeks = (int) round($remainderDays / 7);

        if ($wholeMonths >= 1 && $remWeeks >= 1 && $remWeeks <= 3) {
            $monthPart = $wholeMonths === 1 ? '1 month' : "{$wholeMonths} months";
            $weekPart = $remWeeks === 1 ? '1 week' : "{$remWeeks} weeks";

            return "{$monthPart} and {$weekPart}";
        }

        $roundedMonths = (int) max(1, (int) round($months));

        return $roundedMonths === 1 ? '1 month' : "{$roundedMonths} months";
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
