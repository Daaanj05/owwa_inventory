<?php

namespace App\Support;

class OwwaCellMapping
{
    /**
     * @return array<string, mixed>
     */
    public static function form(string $formCode): array
    {
        return (array) config("owwa_cell_maps.{$formCode}", []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array{cell: string, label: string}>  $headerMap
     * @param  array<string, string|null>  $data
     */
    public static function applyHeader(array &$values, array $headerMap, array $data): void
    {
        foreach ($headerMap as $field => $spec) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $cell = (string) ($spec['cell'] ?? '');
            if ($cell === '') {
                continue;
            }

            $label = (string) ($spec['label'] ?? '');
            $raw = $data[$field];
            $values[$cell] = $label.($raw ?? '');
        }
    }

    public static function columnCell(string $column, int $row): string
    {
        return $column.$row;
    }

    /**
     * @param  array<string, string>  $columns
     */
    public static function detailRowBase(string $formCode): int
    {
        return (int) (self::form($formCode)['detail']['start_row'] ?? 12);
    }

    /**
     * @return array<string, string>
     */
    public static function detailColumns(string $formCode): array
    {
        return (array) (self::form($formCode)['detail']['columns'] ?? []);
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  array<string, string|int|float|null>  $pairs
     */
    public static function applySignatures(array &$values, string $formCode, array $pairs): void
    {
        $signatures = (array) (self::form($formCode)['signatures'] ?? []);

        foreach ($pairs as $field => $value) {
            if (isset($signatures[$field])) {
                $values[$signatures[$field]] = $value;
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function configuredFormCodes(): array
    {
        return array_keys((array) config('owwa_cell_maps', []));
    }
}
