<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class SemiExpendableValueCategory
{
    public const PREFIX_LOW = 'SPLV';

    public const PREFIX_HIGH = 'SPHV';

    public const VALUE_LOW = 'low';

    public const VALUE_HIGH = 'high';

    public static function lowValueMax(): float
    {
        return (float) config('inventory.semi_low_value_max', 5000);
    }

    public static function capThreshold(): float
    {
        return (float) config('inventory.semi_cap_threshold', 50000);
    }

    public static function tierRuleSummary(): string
    {
        return sprintf(
            'SPLV ≤ ₱%s per unit; SPHV > ₱%s and < ₱%s per unit',
            number_format(self::lowValueMax(), 0),
            number_format(self::lowValueMax(), 0),
            number_format(self::capThreshold(), 0),
        );
    }

    /**
     * SPLV when unit cost ≤ low-value max; SPHV when above that and below cap threshold.
     */
    public static function prefixForUnitCost(?float $unitCost): string
    {
        return self::valueTypeForUnitCost($unitCost) === self::VALUE_HIGH
            ? self::PREFIX_HIGH
            : self::PREFIX_LOW;
    }

    public static function valueTypeForUnitCost(?float $unitCost): string
    {
        if ($unitCost === null) {
            return self::VALUE_LOW;
        }

        if ($unitCost <= self::lowValueMax()) {
            return self::VALUE_LOW;
        }

        return self::VALUE_HIGH;
    }

    public static function labelForValueType(?string $valueType): string
    {
        return match ($valueType) {
            self::VALUE_HIGH => self::PREFIX_HIGH.' (High-valued)',
            default => self::PREFIX_LOW.' (Low-valued)',
        };
    }

    public static function labelForUnitCost(?float $unitCost): string
    {
        return self::labelForValueType(self::valueTypeForUnitCost($unitCost));
    }

    /**
     * @throws ValidationException
     */
    public static function assertWithinSemiCap(?float $unitCost): void
    {
        if ($unitCost === null) {
            return;
        }

        if ($unitCost >= self::capThreshold()) {
            throw ValidationException::withMessages([
                'unit_cost' => sprintf(
                    'Semi-expendable items must cost less than ₱%s per unit (COA capitalization threshold). Record this under PPE instead.',
                    number_format(self::capThreshold(), 2),
                ),
            ]);
        }
    }
}
