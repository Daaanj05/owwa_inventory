<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Style\Alignment;

class OwwaExportStandards
{
    public static function blankRowsAfterTransactions(): int
    {
        return (int) config('owwa_export_standards.ledger.blank_rows_after_transactions', 5);
    }

    public static function ledgerRowsForTransactionCount(int $transactionCount, ?int $blankRowsAfter = null): int
    {
        $blankRows = $blankRowsAfter ?? self::blankRowsAfterTransactions();

        return $transactionCount + $blankRows;
    }

    public static function fontName(): string
    {
        return (string) config('owwa_export_standards.ledger.font_name', 'Times New Roman');
    }

    public static function fontSize(): float
    {
        return (float) config('owwa_export_standards.ledger.font_size', 10);
    }

    public static function ledgerRowHeight(): float
    {
        return (float) config('owwa_export_standards.ledger.row_height', 15);
    }

    public static function charsPerLineWidthFactor(): float
    {
        return (float) config('owwa_export_standards.ledger.chars_per_line_width_factor', 1.15);
    }

    public static function charsPerLineWidthOffset(): float
    {
        return (float) config('owwa_export_standards.ledger.chars_per_line_width_offset', 0.5);
    }

    public static function defaultColumnWidth(?string $column = null): float
    {
        $perColumn = config('owwa_export_standards.ledger.default_column_widths');

        if (is_array($perColumn) && $column !== null && isset($perColumn[$column])) {
            return (float) $perColumn[$column];
        }

        return (float) config('owwa_export_standards.ledger.default_column_width', 8.43);
    }

    public static function maxWrapLines(): int
    {
        return max(1, (int) config('owwa_export_standards.ledger.max_wrap_lines', 4));
    }

    /**
     * @param  array<string, string>  $columnTypeMap
     * @return array<int, string>
     */
    public static function wrapTextColumns(array $columnTypeMap): array
    {
        $columns = [];

        foreach ($columnTypeMap as $column => $type) {
            if (in_array($type, ['text', 'reference'], true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $ledger
     * @return array<int, string>
     */
    public static function resolveWrapTextColumns(array $ledger): array
    {
        $override = $ledger['wrap_text_columns'] ?? null;

        if (is_array($override) && $override !== []) {
            return array_values($override);
        }

        return self::wrapTextColumns((array) ($ledger['column_types'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public static function minWrapLinesForExpansion(array $ledger): int
    {
        return max(2, (int) ($ledger['min_wrap_lines_for_expansion'] ?? 2));
    }

    public static function charsPerLineForColumnWidth(float $columnWidth): int
    {
        if ($columnWidth <= 0) {
            $columnWidth = self::defaultColumnWidth();
        }

        return max(1, (int) floor($columnWidth * self::charsPerLineWidthFactor() + self::charsPerLineWidthOffset()));
    }

    /**
     * @param  array<string, string>  $columnTypeMap  column letter => semantic type (date, qty, etc.)
     * @return array<string, string> column letter => horizontal alignment constant
     */
    public static function resolveColumnAlignments(array $columnTypeMap): array
    {
        $typeAlignments = (array) config('owwa_export_standards.ledger.column_types', []);
        $resolved = [];

        foreach ($columnTypeMap as $column => $type) {
            $alignment = $typeAlignments[$type] ?? 'left';
            $resolved[$column] = match ($alignment) {
                'center' => Alignment::HORIZONTAL_CENTER,
                'right' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            };
        }

        return $resolved;
    }
}
