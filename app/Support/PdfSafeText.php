<?php

namespace App\Support;

/**
 * DomPDF (DejaVu) often replaces fancy Unicode punctuation with "???".
 * Normalize common characters to ASCII-safe equivalents for PDF output.
 */
class PdfSafeText
{
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return str_replace(
            [
                "\u{2014}", // em dash —
                "\u{2013}", // en dash –
                "\u{2018}", // ‘
                "\u{2019}", // ’
                "\u{201C}", // “
                "\u{201D}", // ”
                "\u{2026}", // …
                "\u{00A0}", // nbsp
            ],
            [
                '-',
                '-',
                "'",
                "'",
                '"',
                '"',
                '...',
                ' ',
            ],
            $value,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normalizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::normalize($value);
            }
        }

        return $values;
    }
}
