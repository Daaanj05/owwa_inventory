<?php

namespace App\Support;

use App\Models\Issuance;
use App\Models\Transfer;

class RsmiSignatureLayout
{
    public const SIGNATURE_LINE_PLACEHOLDER = '_____________________________________________';

    /**
     * @param  array<string, string|int|float|null>  $values
     * @return array<string, string|int|float|null>
     */
    public static function applySignatureBlock(
        array $values,
        string $reportDate,
        int $rowOffset = 0,
    ): array {
        $signatures = (array) (OwwaCellMapping::form('RSMI')['signatures'] ?? []);

        if (isset($signatures['posted_date']) && filled($reportDate)) {
            $values[self::offsetCell((string) $signatures['posted_date'], $rowOffset)] = $reportDate;
        }

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @return array<string, string|int|float|null>
     */
    public static function applyIssuanceSignatureBlock(
        array $values,
        Issuance $issuance,
        string $reportDate,
        int $rowOffset = 0,
    ): array {
        $issuance->loadMissing(['batch', 'issuedBy']);
        $batch = $issuance->batch;
        $signatures = (array) (OwwaCellMapping::form('RSMI')['signatures'] ?? []);

        $pairs = [
            (string) ($signatures['custodian'] ?? 'A52') => $batch?->custodian_printed_name
                ?? $issuance->custodian_printed_name
                ?? $issuance->issuedBy?->name
                ?? '',
            (string) ($signatures['accounting_staff'] ?? 'F52') => $batch?->accounting_staff_printed_name
                ?? $issuance->accounting_staff_printed_name
                ?? '',
            (string) ($signatures['posted_date'] ?? 'H52') => $reportDate,
        ];

        foreach ($pairs as $cellRef => $value) {
            if (! filled($value)) {
                continue;
            }

            $values[self::offsetCell($cellRef, $rowOffset)] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @return array<string, string|int|float|null>
     */
    public static function applyTransferSignatureBlock(array $values, Transfer $transfer, string $reportDate): array
    {
        return self::applySignatureBlock($values, $reportDate);
    }

    /**
     * @return list<string>
     */
    public static function signatureLineCells(int $rowOffset = 0): array
    {
        $map = OwwaCellMapping::form('RSMI');
        $row = (int) ($map['signature_line_row'] ?? 52) + $rowOffset;
        $columns = (array) ($map['signature_line_columns'] ?? ['A', 'F', 'H']);

        return array_map(
            fn (string $column): string => OwwaCellMapping::columnCell($column, $row),
            $columns,
        );
    }

    public static function signatureLinePlaceholder(string $column): string
    {
        $placeholders = (array) (OwwaCellMapping::form('RSMI')['signature_line_placeholders'] ?? []);

        return (string) ($placeholders[$column] ?? self::SIGNATURE_LINE_PLACEHOLDER);
    }

    public static function offsetCell(string $cellRef, int $rowOffset): string
    {
        if ($rowOffset === 0 || ! preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches)) {
            return $cellRef;
        }

        return $matches[1].((int) $matches[2] + $rowOffset);
    }
}
