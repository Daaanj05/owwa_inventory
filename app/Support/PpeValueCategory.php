<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PpeValueCategory
{
    public static function minimumThreshold(): float
    {
        return SemiExpendableValueCategory::capThreshold();
    }

    public static function minimumRuleSummary(): string
    {
        return sprintf(
            'PPE must cost at least ₱%s per unit (COA capitalization threshold).',
            number_format(self::minimumThreshold(), 0),
        );
    }

    /**
     * @throws ValidationException
     */
    public static function assertMinimumForPpe(?float $unitCost): void
    {
        if ($unitCost === null) {
            return;
        }

        if ($unitCost < self::minimumThreshold()) {
            throw ValidationException::withMessages([
                'unit_cost' => sprintf(
                    'PPE items must cost at least ₱%s per unit (COA capitalization threshold). Record lower-value items under semi-expendable instead.',
                    number_format(self::minimumThreshold(), 2),
                ),
            ]);
        }
    }
}
