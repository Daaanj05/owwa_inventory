<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Whitelist accepted input shapes for item catalog fields.
 */
class ItemMeasurementUnitInput
{
    public const PATTERN = '/^[A-Za-z][A-Za-z\s\-]*$/';

    public static function configure(TextInput $field): TextInput
    {
        return $field
            ->live(onBlur: true)
            ->rules(['required', 'string', 'max:50', 'regex:'.self::PATTERN])
            ->validationMessages([
                'regex' => 'Measurement unit must be letters only (e.g. piece, ream, box).',
            ])
            ->extraAlpineAttributes(WhitelistedTextInput::lettersOnlyAlpineAttributes())
            ->afterStateUpdated(function (Set $set, mixed $state): void {
                if ($state === null || $state === '') {
                    $set('unit', null);

                    return;
                }

                $letters = preg_replace('/[^A-Za-z\s\-]/', '', (string) $state) ?? '';
                $letters = trim(preg_replace('/\s+/', ' ', $letters) ?? '');
                $set('unit', $letters === '' ? null : $letters);
            });
    }

    public static function isValid(?string $unit): bool
    {
        if ($unit === null || trim($unit) === '') {
            return false;
        }

        return (bool) preg_match(self::PATTERN, trim($unit));
    }
}
