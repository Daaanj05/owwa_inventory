<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PurchaseOrderTechnicalSpecificationSheet
{
    private const SPECS_START_ROW = 3;

    private const SPECS_MIN_ROWS = 4;

    private const SPECS_MAX_ROWS = 28;

    private const CHARS_PER_LINE = 95;

    private const LINE_HEIGHT = 16.0;

    public static function append(Spreadsheet $spreadsheet, ?string $technicalSpecifications, ?string $poNumber = null): void
    {
        $title = 'Technical Specification';
        $existingTitles = [];
        foreach ($spreadsheet->getAllSheets() as $existing) {
            $existingTitles[] = $existing->getTitle();
        }

        $sheetTitle = $title;
        $suffix = 1;
        while (in_array($sheetTitle, $existingTitles, true)) {
            $sheetTitle = $title.' '.$suffix;
            $suffix++;
        }

        $source = $spreadsheet->getSheet(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetTitle);

        self::matchColumnWidths($source, $sheet);

        $sheet->setCellValue('A1', 'TECHNICAL SPECIFICATION');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $body = filled($technicalSpecifications) ? (string) $technicalSpecifications : 'N/A';
        $specsRowCount = self::estimateSpecsRowCount($body);
        $specsEndRow = self::SPECS_START_ROW + $specsRowCount - 1;

        $sheet->setCellValue('A'.self::SPECS_START_ROW, $body);
        $sheet->mergeCells('A'.self::SPECS_START_ROW.':F'.$specsEndRow);
        $sheet->getStyle('A'.self::SPECS_START_ROW)->getFont()
            ->setName('Times New Roman')
            ->setSize(11);
        $sheet->getStyle('A'.self::SPECS_START_ROW)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('A'.self::SPECS_START_ROW.':F'.$specsEndRow)
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(Border::BORDER_THIN);

        for ($row = self::SPECS_START_ROW; $row <= $specsEndRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(self::LINE_HEIGHT);
        }

        $conformeTargetRow = $specsEndRow + 2;
        $conformeStartRow = self::findConformeStartRow($source);
        // Official Appendix 61 Conforme block: Conforme / signature / date (6 rows).
        OwwaSpreadsheetLayoutHelper::copyWorksheetRows(
            $source,
            $sheet,
            $conformeStartRow,
            $conformeTargetRow,
            6,
            'F',
        );
        self::clearConformeSideBorders($sheet, $conformeTargetRow, 6);

        $printEndRow = $conformeTargetRow + 5;
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:F'.$printEndRow);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);

        $spreadsheet->setActiveSheetIndex(0);
    }

    protected static function estimateSpecsRowCount(string $body): int
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = 0;

        foreach (explode("\n", $normalized) as $paragraph) {
            $length = max(1, mb_strlen(trim($paragraph) === '' ? ' ' : $paragraph));
            $lines += (int) ceil($length / self::CHARS_PER_LINE);
        }

        // One blank buffer line so the border isn't tight against the last text line.
        $lines = max(self::SPECS_MIN_ROWS, $lines + 1);

        return min(self::SPECS_MAX_ROWS, $lines);
    }

    protected static function clearConformeSideBorders(Worksheet $sheet, int $startRow, int $rowCount): void
    {
        for ($offset = 0; $offset < $rowCount; $offset++) {
            $row = $startRow + $offset;
            $sheet->getStyle('A'.$row)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_NONE);
            $sheet->getStyle('F'.$row)->getBorders()->getRight()->setBorderStyle(Border::BORDER_NONE);
        }
    }

    protected static function matchColumnWidths(Worksheet $source, Worksheet $target): void
    {
        foreach (range('A', 'F') as $column) {
            $width = $source->getColumnDimension($column)->getWidth();
            if ($width > 0) {
                $target->getColumnDimension($column)->setWidth($width);
            }
        }
    }

    protected static function findConformeStartRow(Worksheet $source): int
    {
        $highestRow = min($source->getHighestRow(), 80);

        for ($row = 30; $row <= $highestRow; $row++) {
            $value = trim((string) $source->getCell('A'.$row)->getValue());
            if (str_contains($value, 'Conforme')) {
                return $row;
            }
        }

        return 37;
    }
}
