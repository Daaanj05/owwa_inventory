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

            // Manual accounting blanks stay on the printed OWWA form — never overwrite
            // Fund Cluster / Funds Available / ORS/BURS labels or underscores.
            if (in_array($field, ['fund_cluster', 'funds_available', 'ors_burs_no', 'ors_burs_date'], true)) {
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
     * Row for "Total amount in words" (footer), after optional detail expansion.
     */
    public static function poTotalAmountInWordsRow(int $footerStartRow, int $extraDetailRows = 0): int
    {
        return $footerStartRow + max(0, $extraDetailRows);
    }

    /**
     * Row for "Total amount in numbers" — always the data row immediately before words.
     */
    public static function poTotalAmountInNumbersRow(int $wordsRow): int
    {
        return max(1, $wordsRow - 1);
    }

    /**
     * @return list<string>
     */
    public static function poAccountingPreserveCells(): array
    {
        $cells = (array) (self::form('PO')['accounting_preserve_cells'] ?? []);

        return array_values(array_filter($cells, fn ($cell): bool => is_string($cell) && $cell !== ''));
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
     * @return array<string, array{start_row: int, line_row: int, columns: array<string, string>}>
     */
    public static function physicalCountSignatureBlock(string $formCode, bool $useMaster = false): array
    {
        $map = self::form($formCode);

        if ($useMaster && isset($map['signature_block_master'])) {
            return (array) $map['signature_block_master'];
        }

        return (array) ($map['signature_block'] ?? []);
    }

    public static function physicalCountSignatureCell(
        string $formCode,
        string $field,
        int $rowOffset = 0,
        bool $useMaster = false,
    ): string {
        $block = self::physicalCountSignatureBlock($formCode, $useMaster);
        $lineRow = (int) ($block['line_row'] ?? 38) + $rowOffset;
        $column = (string) (($block['columns'] ?? [])[$field] ?? 'C');

        return self::columnCell($column, $lineRow);
    }

    /**
     * @return list<string>
     */
    public static function configuredFormCodes(): array
    {
        return array_keys((array) config('owwa_cell_maps', []));
    }
}
