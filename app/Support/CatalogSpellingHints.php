<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class CatalogSpellingHints
{
    /**
     * Saved spellings close to what was typed, closest first. At most two, and only when they share that closest distance.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    public static function closest(string $typed, array $options): array
    {
        $typed = trim($typed);

        if ($typed === '') {
            return [];
        }

        $typedKey = mb_strtolower($typed);
        $saved = [];

        foreach ($options as $option) {
            if (! is_string($option)) {
                continue;
            }

            $option = trim($option);

            if ($option === '') {
                continue;
            }

            if (mb_strtolower($option) === $typedKey) {
                return [];
            }

            $saved[$option] = $option;
        }

        $scored = [];

        foreach ($saved as $option) {
            $distance = self::distance($typedKey, mb_strtolower($option));

            if ($distance === null) {
                continue;
            }

            $scored[] = [
                'option' => $option,
                'distance' => $distance,
            ];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, function (array $left, array $right): int {
            if ($left['distance'] !== $right['distance']) {
                return $left['distance'] <=> $right['distance'];
            }

            $length = mb_strlen($left['option']) <=> mb_strlen($right['option']);

            if ($length !== 0) {
                return $length;
            }

            return strcasecmp($left['option'], $right['option']);
        });

        $best = $scored[0]['distance'];
        $matches = [];

        foreach ($scored as $row) {
            if ($row['distance'] !== $best || count($matches) === 2) {
                break;
            }

            $matches[] = $row['option'];
        }

        return $matches;
    }

    /**
     * @param  list<string>  $matches
     */
    public static function sentence(array $matches): ?HtmlString
    {
        $buttons = [];

        foreach (array_slice($matches, 0, 2) as $match) {
            if (! is_string($match) || trim($match) === '') {
                continue;
            }

            $buttons[] = '<button type="button" class="owwa-spell-suggestion" data-value="'.e($match).'">'.e($match).'</button>';
        }

        if ($buttons === []) {
            return null;
        }

        $prompt = count($buttons) === 1
            ? 'Did you mean '.$buttons[0].'?'
            : 'Did you mean '.$buttons[0].' or '.$buttons[1].'?';

        return new HtmlString($prompt);
    }

    private static function distance(string $typed, string $option): ?int
    {
        if ($typed === $option) {
            return null;
        }

        $gaps = array_filter(
            [self::prefixGap($typed, $option), self::editGap($typed, $option)],
            fn (?int $gap): bool => $gap !== null,
        );

        if ($gaps === []) {
            return null;
        }

        return min($gaps);
    }

    private static function prefixGap(string $typed, string $option): ?int
    {
        $shorter = mb_strlen($typed) <= mb_strlen($option) ? $typed : $option;
        $longer = $shorter === $typed ? $option : $typed;

        if (mb_strlen($shorter) < 3 || ! str_starts_with($longer, $shorter)) {
            return null;
        }

        $extra = mb_strlen($longer) - mb_strlen($shorter);

        if ($extra < 1 || $extra > 4) {
            return null;
        }

        return $extra;
    }

    private static function editGap(string $typed, string $option): ?int
    {
        $typedLength = mb_strlen($typed);
        $optionLength = mb_strlen($option);

        if ($typedLength < 4 || $optionLength < 4 || abs($typedLength - $optionLength) > 2) {
            return null;
        }

        $distance = levenshtein($typed, $option);
        $limit = min($typedLength, $optionLength) >= 8 ? 2 : 1;

        if ($distance < 1 || $distance > $limit) {
            return null;
        }

        return $distance;
    }
}
