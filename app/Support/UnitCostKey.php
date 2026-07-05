<?php

namespace App\Support;

class UnitCostKey
{
    public const NULL_SENTINEL = '0.00';

    /**
     * Normalize a unit cost to a canonical decimal string for keys and comparisons.
     */
    public static function normalize(?float $unitCost): string
    {
        if ($unitCost === null) {
            return self::NULL_SENTINEL;
        }

        return number_format(round($unitCost, 2), 2, '.', '');
    }

    /**
     * Convert a normalized key back to float for DB queries.
     */
    public static function toFloat(string $key): float
    {
        return (float) $key;
    }

    /**
     * Build a stock position composite key: item_id_office_id_unitCostKey.
     */
    public static function positionKey(int $itemId, int $officeId, ?float $unitCost): string
    {
        return "{$itemId}_{$officeId}_".self::normalize($unitCost);
    }

    /**
     * Parse a position key into components.
     *
     * @return array{item_id: int, office_id: int, unit_cost: float}|null
     */
    public static function parsePositionKey(string $key): ?array
    {
        $parts = explode('_', $key, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'item_id' => (int) $parts[0],
            'office_id' => (int) $parts[1],
            'unit_cost' => self::toFloat($parts[2]),
        ];
    }

    /**
     * Compare two unit costs for equality after normalization.
     */
    public static function equals(?float $a, ?float $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
