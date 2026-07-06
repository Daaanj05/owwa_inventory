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
        bool $expandWrapRowHeights = true,
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

        if ($wrapTextColumns !== [] && $expandWrapRowHeights) {
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

    public static function expandDetailRowsToFillBlock(
        Worksheet $sheet,
        int $detailStart,
        int $templateDetailRows,
        int $blockEndRow,
        int $styleSourceRow,
    ): void {
        if ($templateDetailRows <= 0 || $blockEndRow < $detailStart) {
            return;
        }

        $detailEndRow = $detailStart + $templateDetailRows - 1;
        $standardRowHeight = self::resolveLedgerRowHeight($sheet, $styleSourceRow);
        $blockHeight = 0.0;

        for ($row = $detailStart; $row <= $blockEndRow; $row++) {
            $height = $sheet->getRowDimension($row)->getRowHeight();

            if ($height <= 0) {
                $height = $standardRowHeight;
            }

            $blockHeight += $height;
        }

        $uniformHeight = max(
            $standardRowHeight,
            OwwaExportStandards::ledgerRowHeight(),
            $blockHeight / $templateDetailRows,
        );

        for ($row = $detailStart; $row <= $detailEndRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight($uniformHeight);
        }
    }

    /**
     * @param  array<int, string>  $expandableColumns
     * @param  array<int, string>  $wrapColumns
     */
    public static function fitWrappedDetailRowsWithinBlock(
        Worksheet $sheet,
        int $detailStart,
        int $templateDetailRows,
        int $blockEndRow,
        int $styleSourceRow,
        int $detailCount,
        array $expandableColumns,
        array $wrapColumns,
        float $maxColumnWidth,
        float $columnWidthStep,
        int $minWrapLinesForExpansion = 2,
        ?float $maxDetailRowHeight = null,
        ?float $blockHeightBudget = null,
    ): void {
        if ($templateDetailRows <= 0 || $detailCount <= 0 || $blockEndRow < $detailStart) {
            return;
        }

        $standardRowHeight = self::resolveLedgerRowHeight($sheet, $styleSourceRow);
        $detailEndRow = $detailStart + $templateDetailRows - 1;
        $templateHeights = [];

        for ($row = $detailStart; $row <= $detailEndRow; $row++) {
            $height = $sheet->getRowDimension($row)->getRowHeight();
            $templateHeights[$row] = $height > 0 ? $height : $standardRowHeight;
        }

        $resolvedBudget = $blockHeightBudget ?? self::sumRowHeightsInRange(
            $sheet,
            $detailStart,
            $detailEndRow,
            $standardRowHeight,
        );
        $maxRowHeight = $maxDetailRowHeight ?? ($resolvedBudget / $templateDetailRows);
        $lastDataRow = $detailStart + $detailCount - 1;

        foreach ($expandableColumns as $column) {
            $currentWidth = self::resolveColumnWidth($sheet, $column);

            while ($currentWidth < $maxColumnWidth) {
                $needsMoreWidth = false;

                for ($row = $detailStart; $row <= $lastDataRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapColumns,
                        $templateHeights[$row],
                        $minWrapLinesForExpansion,
                    );

                    if ($estimatedHeight > $maxRowHeight) {
                        $needsMoreWidth = true;
                        break;
                    }
                }

                if (! $needsMoreWidth) {
                    break;
                }

                $currentWidth = min($maxColumnWidth, $currentWidth + $columnWidthStep);
                $sheet->getColumnDimension($column)->setWidth($currentWidth);
            }
        }

        for ($row = $detailStart; $row <= $lastDataRow; $row++) {
            $templateHeight = $templateHeights[$row];
            $estimatedHeight = self::estimateWrappedRowHeight(
                $sheet,
                $row,
                $wrapColumns,
                $templateHeight,
                $minWrapLinesForExpansion,
            );

            if ($estimatedHeight <= $templateHeight) {
                continue;
            }

            $sheet->getRowDimension($row)->setRowHeight(min($estimatedHeight, $maxRowHeight));
        }

        $actualTotal = 0.0;

        for ($row = $detailStart; $row <= $detailEndRow; $row++) {
            $height = $sheet->getRowDimension($row)->getRowHeight();
            $actualTotal += $height > 0 ? $height : $templateHeights[$row];
        }

        if ($actualTotal <= $resolvedBudget + 0.5) {
            return;
        }

        $scale = $resolvedBudget / $actualTotal;

        for ($row = $detailStart; $row <= $detailEndRow; $row++) {
            $currentHeight = $sheet->getRowDimension($row)->getRowHeight();
            $currentHeight = $currentHeight > 0 ? $currentHeight : $templateHeights[$row];
            $scaledHeight = max($templateHeights[$row], $currentHeight * $scale);
            $sheet->getRowDimension($row)->setRowHeight($scaledHeight);
        }
    }

    /**
     * @param  array<int, string>  $expandableColumns
     * @param  array<int, string>  $wrapColumns
     */
    public static function fitWrappedDetailRowsContinuous(
        Worksheet $sheet,
        int $detailStart,
        int $styleSourceRow,
        int $detailCount,
        array $expandableColumns,
        array $wrapColumns,
        float $maxColumnWidth,
        float $columnWidthStep,
        int $minWrapLinesForExpansion = 2,
        ?float $maxDetailRowHeight = null,
    ): void {
        if ($detailCount <= 0) {
            return;
        }

        $standardRowHeight = self::resolveLedgerRowHeight($sheet, $styleSourceRow);
        $lastDataRow = $detailStart + $detailCount - 1;
        $uncappedMaxRowHeight = $maxDetailRowHeight ?? ($standardRowHeight * OwwaExportStandards::maxWrapLines());

        foreach ($expandableColumns as $column) {
            $currentWidth = self::resolveColumnWidth($sheet, $column);

            while ($currentWidth < $maxColumnWidth) {
                $needsMoreWidth = false;

                for ($row = $detailStart; $row <= $lastDataRow; $row++) {
                    $estimatedHeight = self::estimateWrappedRowHeight(
                        $sheet,
                        $row,
                        $wrapColumns,
                        $standardRowHeight,
                        $minWrapLinesForExpansion,
                    );

                    if ($estimatedHeight > $uncappedMaxRowHeight) {
                        $needsMoreWidth = true;
                        break;
                    }
                }

                if (! $needsMoreWidth) {
                    break;
                }

                $currentWidth = min($maxColumnWidth, $currentWidth + $columnWidthStep);
                $sheet->getColumnDimension($column)->setWidth($currentWidth);
            }
        }

        for ($row = $detailStart; $row <= $lastDataRow; $row++) {
            $estimatedHeight = self::estimateWrappedRowHeight(
                $sheet,
                $row,
                $wrapColumns,
                $standardRowHeight,
                $minWrapLinesForExpansion,
            );

            if ($estimatedHeight <= $standardRowHeight) {
                continue;
            }

            $sheet->getRowDimension($row)->setRowHeight(min($estimatedHeight, $uncappedMaxRowHeight));
        }
    }

    public static function sumRowHeightsInRange(
        Worksheet $sheet,
        int $fromRow,
        int $toRow,
        float $fallbackHeight,
    ): float {
        $total = 0.0;

        for ($row = $fromRow; $row <= $toRow; $row++) {
            $height = $sheet->getRowDimension($row)->getRowHeight();
            $total += $height > 0 ? $height : $fallbackHeight;
        }

        return $total;
    }

    public static function resolveColumnWidth(Worksheet $sheet, string $column): float
    {
        $width = $sheet->getColumnDimension($column)->getWidth();

        if ($width > 0) {
            return $width;
        }

        return OwwaExportStandards::defaultColumnWidth($column);
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
            $bounds = self::parseMergeRangeBounds((string) $mergeRange);

            if ($bounds === null) {
                continue;
            }

            if ($bounds['endRow'] < $sourceStartRow || $bounds['startRow'] > $sourceEndRow) {
                continue;
            }

            $clippedStartRow = max($bounds['startRow'], $sourceStartRow);
            $clippedEndRow = min($bounds['endRow'], $sourceEndRow);

            if ($clippedStartRow > $clippedEndRow) {
                continue;
            }

            $newStartRow = $clippedStartRow + $rowOffset;
            $newEndRow = $clippedEndRow + $rowOffset;
            $newRange = $bounds['startColumn'].$newStartRow.':'.$bounds['endColumn'].$newEndRow;

            self::applyMergeRangeIfSafe($target, $newRange);
        }
    }

    public static function applyMergeRangeIfSafe(Worksheet $sheet, string $mergeRange): void
    {
        $normalizedRange = self::normalizeMergeRange($mergeRange);
        $existingMerges = $sheet->getMergeCells();

        if (isset($existingMerges[$normalizedRange])) {
            return;
        }

        foreach (array_keys($existingMerges) as $existingRange) {
            if (! self::mergeRangesOverlap($normalizedRange, (string) $existingRange)) {
                continue;
            }

            if (strcasecmp($normalizedRange, (string) $existingRange) === 0) {
                return;
            }

            $sheet->unmergeCells((string) $existingRange);
        }

        $sheet->mergeCells($normalizedRange);
    }

    public static function mergeRangesOverlap(string $firstRange, string $secondRange): bool
    {
        $first = self::parseMergeRangeBounds($firstRange);
        $second = self::parseMergeRangeBounds($secondRange);

        if ($first === null || $second === null) {
            return false;
        }

        $rowsOverlap = $first['startRow'] <= $second['endRow'] && $second['startRow'] <= $first['endRow'];
        $columnsOverlap = $first['startColumnIndex'] <= $second['endColumnIndex']
            && $second['startColumnIndex'] <= $first['endColumnIndex'];

        return $rowsOverlap && $columnsOverlap;
    }

    /**
     * @return array{
     *     startColumn: string,
     *     endColumn: string,
     *     startRow: int,
     *     endRow: int,
     *     startColumnIndex: int,
     *     endColumnIndex: int
     * }|null
     */
    public static function parseMergeRangeBounds(string $mergeRange): ?array
    {
        $normalizedRange = self::normalizeMergeRange($mergeRange);
        [$start, $end] = array_pad(explode(':', $normalizedRange, 2), 2, null);

        if ($start === null || $end === null) {
            return null;
        }

        if (! preg_match('/^([A-Z]+)(\d+)$/i', $start, $startMatches)
            || ! preg_match('/^([A-Z]+)(\d+)$/i', $end, $endMatches)) {
            return null;
        }

        $startColumn = strtoupper($startMatches[1]);
        $endColumn = strtoupper($endMatches[1]);
        $startRow = (int) $startMatches[2];
        $endRow = (int) $endMatches[2];
        $startColumnIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumnIndex = Coordinate::columnIndexFromString($endColumn);

        if ($startColumnIndex > $endColumnIndex) {
            [$startColumnIndex, $endColumnIndex] = [$endColumnIndex, $startColumnIndex];
            [$startColumn, $endColumn] = [$endColumn, $startColumn];
        }

        if ($startRow > $endRow) {
            [$startRow, $endRow] = [$endRow, $startRow];
        }

        return [
            'startColumn' => $startColumn,
            'endColumn' => $endColumn,
            'startRow' => $startRow,
            'endRow' => $endRow,
            'startColumnIndex' => $startColumnIndex,
            'endColumnIndex' => $endColumnIndex,
        ];
    }

    public static function normalizeMergeRange(string $mergeRange): string
    {
        $mergeRange = trim(str_replace('$', '', $mergeRange));

        if (! str_contains($mergeRange, ':')) {
            return strtoupper($mergeRange).':'.strtoupper($mergeRange);
        }

        [$start, $end] = explode(':', $mergeRange, 2);

        return strtoupper($start).':'.strtoupper($end);
    }

    /**
     * @return array<int, string>
     */
    public static function overlappingMergePairs(Worksheet $sheet): array
    {
        $ranges = array_map(
            static fn (string $range): string => self::normalizeMergeRange($range),
            array_keys($sheet->getMergeCells()),
        );
        $overlaps = [];

        for ($firstIndex = 0; $firstIndex < count($ranges); $firstIndex++) {
            for ($secondIndex = $firstIndex + 1; $secondIndex < count($ranges); $secondIndex++) {
                if (! self::mergeRangesOverlap($ranges[$firstIndex], $ranges[$secondIndex])) {
                    continue;
                }

                $overlaps[] = $ranges[$firstIndex].' overlaps '.$ranges[$secondIndex];
            }
        }

        return $overlaps;
    }

    public static function applyContinuousPrintLayout(
        Worksheet $sheet,
        string $formCode,
        int $detailCount,
    ): void {
        $highestColumn = PhysicalCountPageLayout::highestColumn($formCode);
        $lastRow = PhysicalCountPageLayout::lastPrintRow($formCode, $detailCount);
        $pageSetup = $sheet->getPageSetup();

        $pageSetup->setPrintArea('A1:'.$highestColumn.$lastRow);
        $pageSetup->setFitToPage(true);
        $pageSetup->setFitToHeight(0);
        $pageSetup->setScale(100);
    }

    public static function applyPhysicalCountStackedPrintLayout(
        Worksheet $sheet,
        string $formCode,
        int $pageCount,
    ): void {
        if ($pageCount <= 0) {
            return;
        }

        $firstBlockStart = PhysicalCountPageLayout::blockStartRowForPage($formCode, 0);
        $lastBlockEndRow = PhysicalCountPageLayout::blockEndRowForPage($formCode, $pageCount - 1);
        $highestColumn = PhysicalCountPageLayout::highestColumn($formCode);
        $pageSetup = $sheet->getPageSetup();

        $pageSetup->setPrintArea(
            'A'.$firstBlockStart.':'.$highestColumn.$lastBlockEndRow,
        );

        if ($pageCount > 1) {
            $pageSetup->setFitToPage(true);
            $pageSetup->setFitToHeight(0);
            $pageSetup->setScale(100);
        }
    }

    public static function columnIndex(string $column): int
    {
        return Coordinate::columnIndexFromString($column);
    }
}
