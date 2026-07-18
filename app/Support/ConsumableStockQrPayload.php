<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Office;

class ConsumableStockQrPayload
{
    public const VERSION = '1';

    public function __construct(
        public int $itemId,
        public int $officeId,
        public ?string $stockNumber = null,
        public ?string $unitCostKey = null,
    ) {}

    public static function encode(Item $item, Office|int $office, ?string $unitCostKey = null): string
    {
        if (config('inventory.qr_public_lookup', true)) {
            return self::publicUrl($item, $office, $unitCostKey);
        }

        return self::encodeLegacy($item, $office, $unitCostKey);
    }

    public static function encodeLegacy(Item $item, Office|int $office, ?string $unitCostKey = null): string
    {
        $officeId = $office instanceof Office ? (int) $office->id : (int) $office;

        $parts = [
            'kind' => 'stock',
            'item' => (string) $item->id,
            'office' => (string) $officeId,
        ];

        $stockNumber = $item->item_code;
        if (filled($stockNumber)) {
            $parts['sn'] = (string) $stockNumber;
        }

        if (filled($unitCostKey)) {
            $parts['uck'] = (string) $unitCostKey;
        }

        $segments = [];
        foreach ($parts as $key => $value) {
            $segments[] = "{$key}={$value}";
        }

        return 'OWWA|'.self::VERSION.'|'.implode('|', $segments);
    }

    public static function publicUrl(Item $item, Office|int $office, ?string $unitCostKey = null): string
    {
        $officeId = $office instanceof Office ? (int) $office->id : (int) $office;

        $params = ['item' => $item->id, 'office' => $officeId];
        if (filled($unitCostKey)) {
            $params['uck'] = $unitCostKey;
        }

        return route('inventory.stock.show', $params);
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

        if (($data['kind'] ?? '') !== 'stock') {
            return null;
        }

        $itemId = isset($data['item']) ? (int) $data['item'] : 0;
        $officeId = isset($data['office']) ? (int) $data['office'] : 0;

        if ($itemId < 1 || $officeId < 1) {
            return null;
        }

        return new self(
            itemId: $itemId,
            officeId: $officeId,
            stockNumber: isset($data['sn']) ? (string) $data['sn'] : null,
            unitCostKey: isset($data['uck']) ? (string) $data['uck'] : null,
        );
    }

    public static function parseFromUrl(string $raw): ?self
    {
        $raw = trim($raw);

        if ($raw === '' || ! str_contains($raw, '/stock/')) {
            return null;
        }

        if (preg_match('~/stock/(\d+)/(\d+)~', $raw, $matches) !== 1) {
            return null;
        }

        $query = [];
        $queryString = parse_url($raw, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        return new self(
            itemId: (int) $matches[1],
            officeId: (int) $matches[2],
            unitCostKey: isset($query['uck']) ? (string) $query['uck'] : null,
        );
    }
}
