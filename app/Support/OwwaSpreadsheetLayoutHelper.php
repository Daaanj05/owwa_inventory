<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OwwaSpreadsheetLayoutHelper
{
    /**
     * @param  array<string, string>  $columnAlignments  column letter => horizontal alignment constant
     * @param  array<int, string>  $dateColumns
     * @param  array<int, string>  $wrapTextColumns
     */
    public static function normalizeLedgerRows(
        Worksheet $sheet,
        int $ledgerStart,
        int $rowCount,
        int $styleSourceRow,
        array $columnAlignments,
        array $dateColumns = ['A'],
        float $dateFontSize = 10.0,
        string $highestColumn = 'L',
        ?int $dataRowCount = null,
        array $wrapTextColumns = [],
        int $minWrapLinesForExpansion = 2,
        bool $uniformDataRowHeight = false,
    ): void {
        if ($rowCount <= 0) {
            return;
        }

        $lastRow = $ledgerStart + $rowCount - 1;
        $lastDataRow = $dataRowCount !== null && $dataRowCount > 0
            ? min($ledgerStart + $dataRowCount - 1, $lastRow)
            : $lastRow;
        $standardRowHeight = self::resolveLedgerRowHeight($sheet, $styleSourceRow);
        $fontName = OwwaExportStandards::fontName();
        $fontSize = OwwaExportStandards::fontSize();
        $columns = range('A', $highestColumn);

        for ($row = $ledgerStart; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight($standardRowHeight);

            if ($row === $styleSourceRow) {
                continue;
            }

            foreach ($columns as $column) {
                $sheet->duplicateStyle(
                    $sheet->getStyle($column.$styleSourceRow),
                    $column.$row,
                );
            }
        }

        for ($row = $ledgerStart; $row <= $lastDataRow; $row++) {
            $sheet->getStyle('A'.$row.':'.$highestColumn.$row)
                ->getFont()
                ->setName($fontName)
                ->setSize($fontSize)
                ->setBold(false);

            foreach ($columns as $column) {
                $coordinate = $column.$row;

                $alignment = $sheet->getStyle($coordinate)->getAlignment();
                $horizontal = $columnAlignments[$column] ?? Alignment::HORIZONTAL_LEFT;
                $alignment->setHorizontal($horizontal);
                $alignment->setVertical(Alignment::VERTICAL_CENTER);
                $alignment->setWrapText(in_array($column, $wrapTextColumns, true));
            }
        }

        if ($wrapTextColumns !== []) {
            if ($uniformDataRowHeight) {
                $maxDataRowHeight = $standardRowHeight;

                for ($row = $ledgerStart; $row <= $lastDataRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapTextColumns,
                        $standardRowHeight,
                        $minWrapLinesForExpansion,
                    );

                    $maxDataRowHeight = max($maxDataRowHeight, $estimatedHeight);
                }

                if ($maxDataRowHeight > $standardRowHeight) {
                    for ($row = $ledgerStart; $row <= $lastDataRow; $row++) {
                        $sheet->getRowDimension($row)->setRowHeight($maxDataRowHeight);
                    }
                }
            } else {
                for ($row = $ledgerStart; $row <= $lastDataRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapTextColumns,
                        $standardRowHeight,
                        $minWrapLinesForExpansion,
                    );

                    if ($estimatedHeight > $standardRowHeight) {
                        $sheet->getRowDimension($row)->setRowHeight($estimatedHeight);
                    }
                }
            }
        }

        self::applyBlockEndBorder($sheet, $ledgerStart, $lastRow, $highestColumn);
    }

    /**
     * @param  array<int, string>  $wrapColumns
     */
    public static function estimateWrappedRowHeight(
        Worksheet $sheet,
        int $row,
        array $wrapColumns,
        float $baseHeight,
        int $minWrapLinesForExpansion = 2,
    ): float {
        $maxLines = 1;

        foreach ($wrapColumns as $column) {
            $value = trim((string) ($sheet->getCell($column.$row)->getValue() ?? ''));

            if ($value === '') {
                continue;
            }

            $columnWidth = self::effectiveColumnWidth($sheet, $column, $row);
            $charsPerLine = max(1, OwwaExportStandards::charsPerLineForColumnWidth($columnWidth));
            $lineCount = self::estimateWrappedLineCount($value, $charsPerLine);

            if ($lineCount <= 1) {
                continue;
            }

            $maxLines = max($maxLines, min($lineCount, OwwaExportStandards::maxWrapLines()));
        }

        if ($maxLines < $minWrapLinesForExpansion) {
            return $baseHeight;
        }

        return max($baseHeight, $maxLines * $baseHeight);
    }

    public static function estimateWrappedLineCount(string $value, int $charsPerLine): int
    {
        if ($value === '' || $charsPerLine < 1) {
            return 1;
        }

        $charDivisionEstimate = (int) ceil(mb_strlen($value) / $charsPerLine);

        if (! str_contains($value, '-')) {
            return max(1, $charDivisionEstimate);
        }

        $segments = explode('-', $value);
        $tokens = [$segments[0]];

        for ($index = 1; $index < count($segments); $index++) {
            $tokens[] = '-'.$segments[$index];
        }

        $lines = 1;
        $currentLength = mb_strlen($tokens[0]);

        for ($index = 1; $index < count($tokens); $index++) {
            $tokenLength = mb_strlen($tokens[$index]);

            if ($currentLength + $tokenLength > $charsPerLine) {
                $lines++;
                $currentLength = $tokenLength;
            } else {
                $currentLength += $tokenLength;
            }
        }

        return max(1, $charDivisionEstimate, $lines);
    }

    public static function effectiveColumnWidth(Worksheet $sheet, string $column, int $row): float
    {
        $coordinate = $column.$row;
        $cell = $sheet->getCell($coordinate);

        foreach ($sheet->getMergeCells() as $mergeRange) {
            if (! $cell->isInRange($mergeRange)) {
                continue;
            }

            [$start, $end] = explode(':', $mergeRange);
            $startColumn = preg_replace('/\d+/', '', $start) ?? 'A';
            $endColumn = preg_replace('/\d+/', '', $end) ?? $startColumn;
            $startIndex = Coordinate::columnIndexFromString($startColumn);
            $endIndex = Coordinate::columnIndexFromString($endColumn);

            $totalWidth = 0.0;
            for ($index = $startIndex; $index <= $endIndex; $index++) {
                $mergeColumn = Coordinate::stringFromColumnIndex($index);
                $width = $sheet->getColumnDimension($mergeColumn)->getWidth();
                $totalWidth += $width > 0 ? $width : OwwaExportStandards::defaultColumnWidth($mergeColumn);
            }

            return $totalWidth;
        }

        $width = $sheet->getColumnDimension($column)->getWidth();

        if ($width <= 0) {
            return OwwaExportStandards::defaultColumnWidth($column);
        }

        return $width;
    }

    public static function resolveLedgerRowHeight(Worksheet $sheet, int $styleSourceRow): float
    {
        $height = $sheet->getRowDimension($styleSourceRow)->getRowHeight();

        if ($height > 0) {
            return $height;
        }

        return OwwaExportStandards::ledgerRowHeight();
    }

    public static function applyBoldLabelPlainValueCell(
        Worksheet $sheet,
        string $cell,
        string $label,
        string $value,
    ): void {
        $cellFont = $sheet->getStyle($cell)->getFont();
        $fontName = $cellFont->getName() ?: OwwaExportStandards::fontName();
        $fontSize = $cellFont->getSize() ?: OwwaExportStandards::fontSize();

        $richText = new RichText;
        $labelRun = $richText->createTextRun($label);
        $labelRun->getFont()->setName($fontName);
        $labelRun->getFont()->setSize($fontSize);
        $labelRun->getFont()->setBold(true);
        $labelRun->getFont()->setUnderline(Font::UNDERLINE_NONE);

        $valueRun = $richText->createTextRun($value);
        $valueRun->getFont()->setName($fontName);
        $valueRun->getFont()->setSize($fontSize);
        $valueRun->getFont()->setBold(false);
        $valueRun->getFont()->setUnderline(Font::UNDERLINE_NONE);

        $sheet->getCell($cell)->setValue($richText);
        $sheet->getStyle($cell)->getFont()->setName($fontName);
        $sheet->getStyle($cell)->getFont()->setSize($fontSize);
        $sheet->getStyle($cell)->getFont()->setBold(false);
        $sheet->getStyle($cell)->getFont()->setUnderline(Font::UNDERLINE_NONE);
    }

    public static function clearRowRange(
        Worksheet $sheet,
        int $fromRow,
        int $toRow,
        string $highestColumn = 'L',
    ): void {
        if ($fromRow > $toRow) {
            return;
        }

        for ($row = $fromRow; $row <= $toRow; $row++) {
            foreach (range('A', $highestColumn) as $column) {
                $coordinate = $column.$row;
                $sheet->setCellValue($coordinate, null);

                $borders = $sheet->getStyle($coordinate)->getBorders();
                $borders->getTop()->setBorderStyle(Border::BORDER_NONE);
                $borders->getBottom()->setBorderStyle(Border::BORDER_NONE);
                $borders->getLeft()->setBorderStyle(Border::BORDER_NONE);
                $borders->getRight()->setBorderStyle(Border::BORDER_NONE);
            }
        }
    }

    /**
     * @param  array<string, string>  $columnAlignments
     * @param  array<int, string>  $wrapTextColumns
     */
    public static function normalizeDetailRows(
        Worksheet $sheet,
        int $detailStart,
        int $rowCount,
        int $styleSourceRow,
        array $columnAlignments,
        string $highestColumn = 'O',
        array $wrapTextColumns = [],
        int $minWrapLinesForExpansion = 2,
        bool $uniformDataRowHeight = false,
    ): void {
        if ($rowCount <= 0) {
            return;
        }

        $lastRow = $detailStart + $rowCount - 1;
        $fontName = OwwaExportStandards::fontName();
        $fontSize = OwwaExportStandards::fontSize();
        $standardRowHeight = self::resolveLedgerRowHeight($sheet, $styleSourceRow);
        $columns = range('A', $highestColumn);

        for ($row = $detailStart; $row <= $lastRow; $row++) {
            foreach ($columns as $column) {
                if (! array_key_exists($column, $columnAlignments)) {
                    continue;
                }

                $sheet->duplicateStyle(
                    $sheet->getStyle($column.$styleSourceRow),
                    $column.$row,
                );

                $alignment = $sheet->getStyle($column.$row)->getAlignment();
                $alignment->setHorizontal($columnAlignments[$column]);
                $alignment->setVertical(Alignment::VERTICAL_CENTER);
                $alignment->setWrapText(in_array($column, $wrapTextColumns, true));

                $font = $sheet->getStyle($column.$row)->getFont();
                $font->setName($fontName);
                $font->setSize($fontSize);
                $font->setBold(false);
                $font->setStrikethrough(false);
                $font->setUnderline(Font::UNDERLINE_NONE);
            }
        }

        if ($wrapTextColumns !== []) {
            if ($uniformDataRowHeight) {
                $maxDataRowHeight = $standardRowHeight;

                for ($row = $detailStart; $row <= $lastRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapTextColumns,
                        $standardRowHeight,
                        $minWrapLinesForExpansion,
                    );

                    $maxDataRowHeight = max($maxDataRowHeight, $estimatedHeight);
                }

                if ($maxDataRowHeight > $standardRowHeight) {
                    for ($row = $detailStart; $row <= $lastRow; $row++) {
                        $sheet->getRowDimension($row)->setRowHeight($maxDataRowHeight);
                    }
                }
            } else {
                for ($row = $detailStart; $row <= $lastRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapTextColumns,
                        $standardRowHeight,
                        $minWrapLinesForExpansion,
                    );

                    if ($estimatedHeight > $standardRowHeight) {
                        $sheet->getRowDimension($row)->setRowHeight($estimatedHeight);
                    }
                }
            }
        }

        self::applyBlockEndBorder($sheet, $detailStart, $lastRow, $highestColumn);
    }

    /**
     * @param  array<string, string>  $columnTypes
     */
    public static function applyMonetaryColumnFormats(
        Worksheet $sheet,
        int $fromRow,
        int $toRow,
        array $columnTypes,
    ): void {
        if ($fromRow > $toRow || $columnTypes === []) {
            return;
        }

        $formatCode = OwwaExportStandards::currencyExcelFormatCode();
        $monetaryColumns = [];

        foreach ($columnTypes as $column => $type) {
            if (in_array($type, ['unit_cost', 'amount'], true)) {
                $monetaryColumns[] = $column;
            }
        }

        if ($monetaryColumns === []) {
            return;
        }

        foreach (range($fromRow, $toRow) as $row) {
            foreach ($monetaryColumns as $column) {
                $coordinate = $column.$row;
                $value = $sheet->getCell($coordinate)->getValue();

                if ($value === null || $value === '' || ! is_numeric($value)) {
                    continue;
                }

                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($formatCode);
            }
        }
    }

    public static function applyBlockEndBorder(
        Worksheet $sheet,
        int $ledgerStart,
        int $lastLedgerRow,
        string $highestColumn = 'L',
    ): void {
        foreach (range($ledgerStart, $lastLedgerRow) as $row) {
            $borderStyle = $row === $lastLedgerRow ? Border::BORDER_MEDIUM : Border::BORDER_THIN;

            foreach (range('A', $highestColumn) as $column) {
                $sheet->getStyle($column.$row)
                    ->getBorders()
                    ->getBottom()
                    ->setBorderStyle($borderStyle);
            }
        }
    }

    public static function duplicateHeaderDrawings(
        Worksheet $masterSheet,
        Worksheet $targetSheet,
        int $rowOffset,
        int $headerRowCount = 7,
    ): void {
        if ($rowOffset <= 0) {
            return;
        }

        foreach ($masterSheet->getDrawingCollection() as $drawing) {
            if (! $drawing instanceof BaseDrawing) {
                continue;
            }

            $anchorRow = self::cellRow($drawing->getCoordinates());
            if ($anchorRow < 1 || $anchorRow > $headerRowCount) {
                continue;
            }

            $clone = self::cloneDrawing($drawing);
            $clone->setCoordinates(self::shiftCellRow($drawing->getCoordinates(), $rowOffset));

            if ($drawing->getCoordinates2() !== '') {
                $clone->setCoordinates2(self::shiftCellRow($drawing->getCoordinates2(), $rowOffset));
            }

            $clone->setWorksheet($targetSheet);
        }
    }

    protected static function cloneDrawing(BaseDrawing $drawing): BaseDrawing
    {
        $clone = clone $drawing;

        $worksheetProperty = new \ReflectionProperty($clone, 'worksheet');
        $worksheetProperty->setAccessible(true);
        $worksheetProperty->setValue($clone, null);

        return $clone;
    }

    protected static function shiftCellRow(string $coordinate, int $rowOffset): string
    {
        preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $matches);

        return ($matches[1] ?? 'A').((int) ($matches[2] ?? 1) + $rowOffset);
    }

    protected static function cellRow(string $coordinate): int
    {
        return (int) preg_replace('/\D+/', '', $coordinate);
    }

    /**
     * @param  array<string, string>  $columnAlignments  column letter => horizontal alignment constant
     */
    public static function applyLedgerRowAlignments(
        Worksheet $sheet,
        int $fromRow,
        int $toRow,
        array $columnAlignments,
    ): void {
        foreach (range($fromRow, $toRow) as $row) {
            foreach ($columnAlignments as $column => $horizontal) {
                $sheet->getStyle($column.$row)->getAlignment()->setHorizontal($horizontal);
            }
        }
    }

    public static function copyWorksheetRows(
        Worksheet $source,
        Worksheet $target,
        int $sourceStartRow,
        int $targetStartRow,
        int $rowCount,
        string $highestColumn = 'L',
    ): void {
        if ($rowCount <= 0) {
            return;
        }

        $sourceEndRow = $sourceStartRow + $rowCount - 1;

        self::copyMergedCellsInRowRange($source, $target, $sourceStartRow, $sourceEndRow, $targetStartRow);

        for ($offset = 0; $offset < $rowCount; $offset++) {
            $sourceRow = $sourceStartRow + $offset;
            $targetRow = $targetStartRow + $offset;

            $target->getRowDimension($targetRow)->setRowHeight(
                $source->getRowDimension($sourceRow)->getRowHeight(),
            );

            foreach (range('A', $highestColumn) as $column) {
                $sourceCoordinate = $column.$sourceRow;
                $targetCoordinate = $column.$targetRow;
                $sourceCell = $source->getCell($sourceCoordinate);

                $target->setCellValue($targetCoordinate, $sourceCell->getValue());
                $target->duplicateStyle($sourceCell->getStyle(), $targetCoordinate);
            }
        }
    }

    public static function insertStyledLedgerRows(
        Worksheet $sheet,
        int $insertBeforeRow,
        int $rowCount,
        int $styleSourceRow,
        string $highestColumn = 'L',
    ): void {
        if ($rowCount <= 0) {
            return;
        }

        $sheet->insertNewRowBefore($insertBeforeRow, $rowCount);

        for ($offset = 0; $offset < $rowCount; $offset++) {
            $targetRow = $insertBeforeRow + $offset;

            $sheet->getRowDimension($targetRow)->setRowHeight(-1);

            foreach (range('A', $highestColumn) as $column) {
                $sheet->duplicateStyle(
                    $sheet->getStyle($column.$styleSourceRow),
                    $column.$targetRow,
                );
                $sheet->setCellValue($column.$targetRow, null);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public static function alignmentMapFromConfig(array $alignments): array
    {
        $resolved = [];

        foreach ($alignments as $column => $alignment) {
            $resolved[$column] = match ($alignment) {
                'center' => Alignment::HORIZONTAL_CENTER,
                'right' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            };
        }

        return $resolved;
    }

    protected static function copyMergedCellsInRowRange(
        Worksheet $source,
        Worksheet $target,
        int $sourceStartRow,
        int $sourceEndRow,
        int $targetStartRow,
    ): void {
        $rowOffset = $targetStartRow - $sourceStartRow;

        foreach ($source->getMergeCells() as $mergeRange) {
            [$start, $end] = explode(':', $mergeRange);
            $startColumn = preg_replace('/\d+/', '', $start) ?? 'A';
            $startRow = (int) preg_replace('/\D+/', '', $start);
            $endColumn = preg_replace('/\d+/', '', $end) ?? $startColumn;
            $endRow = (int) preg_replace('/\D+/', '', $end);

            if ($startRow < $sourceStartRow || $startRow > $sourceEndRow) {
                continue;
            }

            $newStartRow = $startRow + $rowOffset;
            $newEndRow = $endRow + $rowOffset;
            $newRange = $startColumn.$newStartRow.':'.$endColumn.$newEndRow;

            if (! in_array($newRange, $target->getMergeCells(), true)) {
                $target->mergeCells($newRange);
            }
        }
    }

    public static function columnIndex(string $column): int
    {
        return Coordinate::columnIndexFromString($column);
    }
}
