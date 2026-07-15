<?php

namespace App\Support;

class RequisitionLineFulfillmentState
{
    public const IN_STOCK = 'in_stock';

    public const BACKORDERED = 'backordered';

    public const PARTIALLY_ISSUED = 'partially_issued';

    public const FULLY_ISSUED = 'fully_issued';

    public static function label(string $state): string
    {
        return match ($state) {
            self::BACKORDERED => 'Backordered',
            self::PARTIALLY_ISSUED => 'Partially issued',
            self::FULLY_ISSUED => 'Fully issued',
            default => 'In stock',
        };
    }

    public static function color(string $state): string
    {
        return match ($state) {
            self::BACKORDERED => 'warning',
            self::PARTIALLY_ISSUED => 'info',
            self::FULLY_ISSUED => 'success',
            default => 'gray',
        };
    }
}
