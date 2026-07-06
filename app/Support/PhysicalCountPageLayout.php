<?php

namespace App\Support;

class PhysicalCountPageLayout
{
    public static function blockStartRowForPage(string $formCode, int $pageIndex): int
    {
        $page = self::pageConfig($formCode);

        return (int) ($page['block_start_row'] ?? 1)
            + ($pageIndex * (int) ($page['block_row_count'] ?? 40));
    }

    public static function blockRowCount(string $formCode): int
    {
        return (int) (self::pageConfig($formCode)['block_row_count'] ?? 40);
    }

    public static function blockEndRowForPage(string $formCode, int $pageIndex): int
    {
        return self::blockStartRowForPage($formCode, $pageIndex) + self::blockRowCount($formCode) - 1;
    }

    public static function isContinuousLayout(string $formCode): bool
    {
        $detail = (array) (OwwaCellMapping::form($formCode)['detail'] ?? []);

        return ($detail['layout_mode'] ?? 'stacked') === 'continuous';
    }

    public static function templateDetailRows(string $formCode): int
    {
        $detail = (array) (OwwaCellMapping::form($formCode)['detail'] ?? []);

        return (int) ($detail['template_detail_rows'] ?? $detail['max_rows'] ?? 21);
    }

    public static function extraDetailRows(string $formCode, int $detailCount): int
    {
        return max(0, $detailCount - self::templateDetailRows($formCode));
    }

    public static function signatureLineRow(string $formCode, int $detailCount, bool $useMaster = false): int
    {
        $block = OwwaCellMapping::physicalCountSignatureBlock($formCode, $useMaster);

        return (int) ($block['line_row'] ?? 39) + self::extraDetailRows($formCode, $detailCount);
    }

    public static function lastPrintRow(string $formCode, int $detailCount): int
    {
        $block = OwwaCellMapping::physicalCountSignatureBlock($formCode);

        return (int) ($block['line_row'] ?? 39) + 1 + self::extraDetailRows($formCode, $detailCount);
    }

    public static function rowsPerPage(string $formCode): int
    {
        return (int) (self::pageConfig($formCode)['rows_per_page']
            ?? OwwaCellMapping::form($formCode)['detail']['max_rows']
            ?? 21);
    }

    public static function highestColumn(string $formCode): string
    {
        return (string) (self::pageConfig($formCode)['highest_column']
            ?? OwwaCellMapping::form($formCode)['detail']['highest_column']
            ?? 'K');
    }

    public static function pagesNeeded(string $formCode, int $lineCount): int
    {
        if ($lineCount <= 0) {
            return 1;
        }

        return (int) ceil($lineCount / self::rowsPerPage($formCode));
    }

    public static function rowOffsetForBlock(string $formCode, int $blockStartRow): int
    {
        return $blockStartRow - self::blockStartRowForPage($formCode, 0);
    }

    public static function detailStartRowForBlock(string $formCode, int $blockStartRow): int
    {
        return $blockStartRow + OwwaCellMapping::detailRowBase($formCode) - self::blockStartRowForPage($formCode, 0);
    }

    public static function headerCell(string $formCode, string $field, int $blockStartRow): string
    {
        $cell = (string) (OwwaCellMapping::form($formCode)['header'][$field]['cell'] ?? '');

        return self::shiftCell($cell, self::rowOffsetForBlock($formCode, $blockStartRow));
    }

    public static function signatureCellForBlock(
        string $formCode,
        string $field,
        int $blockStartRow,
        bool $useMaster = false,
        ?int $detailRowCount = null,
    ): string {
        $rowOffset = self::rowOffsetForBlock($formCode, $blockStartRow);

        if ($detailRowCount !== null && self::isContinuousLayout($formCode)) {
            $rowOffset += self::extraDetailRows($formCode, $detailRowCount);
        }

        return OwwaCellMapping::physicalCountSignatureCell(
            $formCode,
            $field,
            $rowOffset,
            $useMaster,
        );
    }

    public static function shiftCell(string $cell, int $rowOffset): string
    {
        if ($rowOffset === 0 || ! preg_match('/^([A-Z]+)(\d+)$/i', $cell, $matches)) {
            return $cell;
        }

        return strtoupper($matches[1]).((int) $matches[2] + $rowOffset);
    }

    /**
     * @param  array<string, string|int|float|null>  $cellValues
     * @return array<string, string|int|float|null>
     */
    public static function shiftCellValues(array $cellValues, int $rowOffset): array
    {
        if ($rowOffset === 0) {
            return $cellValues;
        }

        $shifted = [];

        foreach ($cellValues as $cell => $value) {
            $shifted[self::shiftCell((string) $cell, $rowOffset)] = $value;
        }

        return $shifted;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function pageConfig(string $formCode): array
    {
        return (array) (OwwaCellMapping::form($formCode)['detail']['page'] ?? []);
    }
}
