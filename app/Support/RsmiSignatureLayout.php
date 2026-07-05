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
    ): array {
        $signatures = (array) (OwwaCellMapping::form('RSMI')['signatures'] ?? []);

        if (isset($signatures['posted_date'])) {
            $values[$signatures['posted_date']] = $reportDate;
        }

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @return array<string, string|int|float|null>
     */
    public static function applyIssuanceSignatureBlock(array $values, Issuance $issuance, string $reportDate): array
    {
        return self::applySignatureBlock($values, $reportDate);
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
    public static function signatureLineCells(): array
    {
        $map = OwwaCellMapping::form('RSMI');
        $row = (int) ($map['signature_line_row'] ?? 52);
        $columns = (array) ($map['signature_line_columns'] ?? ['A', 'F', 'H']);

        return array_map(
            fn (string $column): string => OwwaCellMapping::columnCell($column, $row),
            $columns,
        );
    }
}
