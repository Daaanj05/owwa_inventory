<?php

namespace App\Support;

use App\Models\InventoryUnit;

class InventoryUnitQrPayload
{
    public const VERSION = '1';

    public function __construct(
        public string $propertyNumber,
        public ?int $itemId = null,
        public ?int $officeId = null,
        public ?string $stockNumber = null,
    ) {}

    public static function encode(InventoryUnit $unit): string
    {
        if (config('inventory.qr_public_lookup', true)) {
            return self::publicUrl($unit);
        }

        return self::encodeLegacy($unit);
    }

    public static function encodeLegacy(InventoryUnit $unit): string
    {
        $unit->loadMissing(['item']);

        $parts = [
            'pn' => $unit->property_number,
            'item' => (string) $unit->item_id,
            'office' => (string) $unit->office_id,
        ];

        $stockNumber = $unit->stock_number ?? $unit->item?->item_code;
        if (filled($stockNumber)) {
            $parts['sn'] = (string) $stockNumber;
        }

        $segments = [];
        foreach ($parts as $key => $value) {
            $segments[] = "{$key}={$value}";
        }

        return 'OWWA|'.self::VERSION.'|'.implode('|', $segments);
    }

    public static function publicUrl(InventoryUnit|string $unitOrPropertyNumber): string
    {
        $propertyNumber = $unitOrPropertyNumber instanceof InventoryUnit
            ? (string) $unitOrPropertyNumber->property_number
            : trim($unitOrPropertyNumber);

        return route('inventory.assets.show', ['propertyNumber' => $propertyNumber]);
    }

    public static function resolve(string $raw): ?self
    {
        return self::parse($raw) ?? self::parseFromUrl($raw);
    }

    public static function parse(string $raw): ?self
    {
        $raw = trim($raw);

        if (! str_starts_with(strtoupper($raw), 'OWWA|')) {
            return null;
        }

        $segments = explode('|', $raw);
        if (count($segments) < 3) {
            return null;
        }

        $data = [];
        for ($i = 2; $i < count($segments); $i++) {
            $pair = explode('=', $segments[$i], 2);
            if (count($pair) === 2) {
                $data[$pair[0]] = $pair[1];
            }
        }

        $propertyNumber = trim((string) ($data['pn'] ?? ''));
        if ($propertyNumber === '') {
            return null;
        }

        return new self(
            propertyNumber: $propertyNumber,
            itemId: isset($data['item']) ? (int) $data['item'] : null,
            officeId: isset($data['office']) ? (int) $data['office'] : null,
            stockNumber: isset($data['sn']) ? (string) $data['sn'] : null,
        );
    }

    public static function parseFromUrl(string $raw): ?self
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, '/assets/')) {
            return null;
        }

        if (preg_match('~/assets/([^?#]+)~', $raw, $matches) !== 1) {
            return null;
        }

        $propertyNumber = rawurldecode($matches[1]);
        if ($propertyNumber === '') {
            return null;
        }

        return new self(propertyNumber: $propertyNumber);
    }
}
