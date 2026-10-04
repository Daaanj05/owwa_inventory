<?php

namespace App\Services;

use App\Models\InspectionAcceptanceReport;
use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fast Appendix 62 IAR Excel: PhpSpreadsheet lookalike using Fast PDF page data.
 */
class InspectionAcceptanceReportFastExcelExportService
{
    public function __construct(
        protected InspectionAcceptanceReportFastPdfExportService $fastPdfExport,
    ) {}

    public function download(InspectionAcceptanceReport $iar): StreamedResponse
    {
        $pages = $this->fastPdfExport->buildPages($iar);
        abort_if($pages === [], 404, 'No inspection acceptance report pages could be built.');

        $spreadsheet = $this->makeSpreadsheet($pages);
        $filename = OwwaExportFilename::transaction(
            'IAR-fast',
            $iar->number ?? (string) $iar->id,
            'xlsx',
        );

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * @param  Collection<int, InspectionAcceptanceReport>|iterable<int, InspectionAcceptanceReport>  $iars
     */
    public function downloadMany(iterable $iars): StreamedResponse
    {
        $pages = [];

        foreach ($iars as $iar) {
            array_push($pages, ...$this->fastPdfExport->buildPages($iar));
        }

        abort_if($pages === [], 404, 'No inspection acceptance reports could be built for the selected date range.');

        return $this->streamXlsx(
            $this->makeSpreadsheet($pages),
            OwwaExportFilename::batch('IAR-fast', ext: 'xlsx'),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function makeSpreadsheet(array $pages): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $usedTitles = [];
        $first = true;

        foreach ($pages as $page) {
            $sheet = $first
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $first = false;

            $titleBase = filled($page['iar_no'] ?? null)
                ? (string) $page['iar_no']
                : 'IAR';
            $sheet->setTitle($this->uniqueSheetTitle($titleBase, $usedTitles));
            $this->fillPageSheet($sheet, $page);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    protected function fillPageSheet(Worksheet $sheet, array $page): void
    {
        $sheet->getParent()?->getDefaultStyle()->getFont()
            ->setName('Times New Roman')
            ->setSize(11);

        // Official IAR visual columns (A | B:C | D | E:F), collapsed to 4 cols.
        $sheet->getColumnDimension('A')->setWidth(15.4);
        $sheet->getColumnDimension('B')->setWidth(44.8);
        $sheet->getColumnDimension('C')->setWidth(12.9);
        $sheet->getColumnDimension('D')->setWidth(18.8);

        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(22);

        $agencyStyle = [
            'font' => ['bold' => false, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
        $sheet->mergeCells('B1:C1');
        $sheet->setCellValue('B1', 'Republic of the Philippines');
        $sheet->getStyle('B1')->applyFromArray($agencyStyle);

        $sheet->mergeCells('B2:C2');
        $sheet->setCellValue('B2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION');
        $sheet->getStyle('B2')->applyFromArray($agencyStyle);

        $sheet->mergeCells('B3:C3');
        $sheet->setCellValue('B3', 'INSPECTION AND ACCEPTANCE REPORT');
        $sheet->getStyle('B3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $this->addHeaderLogos($sheet);

        $boldFont = ['font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11]];
        $thin = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ];

        $sheet->mergeCells('A5:B5');
        $sheet->mergeCells('C5:D5');
        $sheet->setCellValue('A5', 'Entity Name : '.((string) ($page['entity_name'] ?? '')));
        $sheet->setCellValue('C5', 'Fund Cluster : ');
        $sheet->getStyle('A5')->applyFromArray($boldFont);
        $sheet->getStyle('C5')->applyFromArray($boldFont);

        $sheet->mergeCells('A6:B6');
        $sheet->mergeCells('C6:D6');
        $sheet->setCellValue('A6', 'Supplier : '.((string) ($page['supplier'] ?? '')));
        $sheet->setCellValue('C6', 'IAR No. : '.((string) ($page['iar_no'] ?? '')));
        $sheet->getStyle('C6')->applyFromArray($boldFont);

        $sheet->mergeCells('A7:B7');
        $sheet->mergeCells('C7:D7');
        $sheet->setCellValue('A7', 'PO No./Date : '.((string) ($page['po_no_date'] ?? '')));
        $sheet->setCellValue('C7', 'Date : '.((string) ($page['date'] ?? '')));
        $sheet->getStyle('C7')->applyFromArray($boldFont);

        $sheet->mergeCells('A8:B8');
        $sheet->mergeCells('C8:D8');
        $sheet->setCellValue('A8', 'Requisitioning Office/Dept. : '.((string) ($page['requisitioning_office'] ?? '')));
        $sheet->setCellValue('C8', 'Invoice No. : '.((string) ($page['invoice_no'] ?? '')));

        $sheet->mergeCells('A9:B9');
        $sheet->mergeCells('C9:D9');
        $sheet->setCellValue('A9', 'Responsibility Center Code : '.((string) ($page['responsibility_center_code'] ?? '')));
        $sheet->setCellValue('C9', 'Date : '.((string) ($page['invoice_date'] ?? '')));

        $sheet->getStyle('A6:D9')->applyFromArray($thin);

        $sheet->setCellValue('A10', "Stock/\nProperty No.");
        $sheet->setCellValue('B10', 'Description');
        $sheet->setCellValue('C10', 'Unit');
        $sheet->setCellValue('D10', 'Quantity');
        $headerStyle = [
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];
        $sheet->getStyle('A10:D10')->applyFromArray($headerStyle);
        $sheet->getStyle('A10:D10')->applyFromArray($thin);
        $sheet->getRowDimension(10)->setRowHeight(28);

        $lines = $page['lines'] ?? [];
        $maxRows = $this->fastPdfExport->maxRowsPerPage();
        $startRow = 11;
        $endRow = $startRow + $maxRows - 1;

        foreach ($lines as $index => $line) {
            $row = $startRow + $index;
            if ($row > $endRow) {
                break;
            }

            $sheet->setCellValue('A'.$row, $line['stock_no'] ?? '');
            $sheet->setCellValue('B'.$row, $line['description'] ?? '');
            $sheet->setCellValue('C'.$row, $line['unit'] ?? '');
            $sheet->setCellValue('D'.$row, $line['quantity'] ?? '');
        }

        $sheet->getStyle('A'.$startRow.':D'.$endRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$startRow.':D'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_TOP);

        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $sectionRow = $endRow + 1;
        $sheet->mergeCells('A'.$sectionRow.':B'.$sectionRow);
        $sheet->mergeCells('C'.$sectionRow.':D'.$sectionRow);
        $sheet->setCellValue('A'.$sectionRow, 'INSPECTION');
        $sheet->setCellValue('C'.$sectionRow, 'ACCEPTANCE');
        $sheet->getStyle('A'.$sectionRow.':D'.$sectionRow)->applyFromArray([
            ...$thin,
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $dateRow = $sectionRow + 1;
        $sheet->mergeCells('A'.$dateRow.':B'.$dateRow);
        $sheet->mergeCells('C'.$dateRow.':D'.$dateRow);
        $sheet->setCellValue(
            'A'.$dateRow,
            'Date Inspected : '.($includeFooter ? (string) ($page['date_inspected'] ?? '') : ''),
        );
        $sheet->setCellValue(
            'C'.$dateRow,
            'Date Received : '.($includeFooter ? (string) ($page['date_received'] ?? '') : ''),
        );
        $sheet->getStyle('A'.$dateRow.':D'.$dateRow)->applyFromArray($thin);

        $bodyRow = $dateRow + 1;
        $sheet->mergeCells('A'.$bodyRow.':B'.$bodyRow);
        $sheet->mergeCells('C'.$bodyRow.':D'.$bodyRow);
        $sheet->setCellValue(
            'A'.$bodyRow,
            'Inspected, verified and found in order as to quantity and specifications',
        );
        $sheet->setCellValue('C'.$bodyRow, "☐ Complete\n☐ Partial (pls. specify quantity)");
        $sheet->getStyle('A'.$bodyRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('C'.$bodyRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($bodyRow)->setRowHeight(36);
        $sheet->getStyle('A'.$bodyRow.':D'.$bodyRow)->applyFromArray($thin);

        $sigRow = $bodyRow + 1;
        $sheet->mergeCells('A'.$sigRow.':B'.$sigRow);
        $sheet->mergeCells('C'.$sigRow.':D'.$sigRow);
        if ($includeFooter) {
            $sheet->setCellValue('A'.$sigRow, (string) ($page['inspection_officer_name'] ?? ''));
            $sheet->setCellValue('C'.$sigRow, (string) ($page['custodian_name'] ?? ''));
        }
        $sheet->getStyle('A'.$sigRow.':D'.$sigRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$sigRow.':D'.$sigRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_BOTTOM);

        $capRow = $sigRow + 1;
        $sheet->mergeCells('A'.$capRow.':B'.$capRow);
        $sheet->mergeCells('C'.$capRow.':D'.$capRow);
        $sheet->setCellValue('A'.$capRow, 'Inspection Officer/Inspection Committee');
        $sheet->setCellValue('C'.$capRow, 'Supply and/or Property Custodian');
        $sheet->getStyle('A'.$capRow.':D'.$capRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$capRow.':D'.$capRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        // Official IAR margins (inches).
        $sheet->getPageMargins()->setLeft(1.25);
        $sheet->getPageMargins()->setRight(1.0);
        $sheet->getPageMargins()->setTop(1.0);
        $sheet->getPageMargins()->setBottom(0.5);
    }

    protected function addHeaderLogos(Worksheet $sheet): void
    {
        $bagongPath = public_path(config('owwa_mail.logos.bagong_pilipinas', 'images/bagong-pilipinas-form-logo.png'));
        $owwaPath = public_path(config('owwa_mail.logos.owwa', 'images/owwa-form-logo.png'));
        $logoHeightPx = (int) round(2.33 * 96 / 2.54);

        if (is_readable($owwaPath)) {
            $owwa = new Drawing;
            $owwa->setName('OWWA');
            $owwa->setPath($owwaPath);
            $owwa->setResizeProportional(true);
            $owwa->setHeight($logoHeightPx);
            $owwa->setCoordinates('A1');
            $owwa->setOffsetX(4);
            $owwa->setOffsetY(0);
            $owwa->setWorksheet($sheet);
        }

        if (is_readable($bagongPath)) {
            $bagong = new Drawing;
            $bagong->setName('Bagong Pilipinas');
            $bagong->setPath($bagongPath);
            $bagong->setResizeProportional(true);
            $bagong->setHeight($logoHeightPx);
            $bagong->setCoordinates('D1');
            $bagong->setOffsetX(4);
            $bagong->setOffsetY(0);
            $bagong->setWorksheet($sheet);
        }
    }

    /**
     * @param  array<string, true>  $usedTitles
     */
    protected function uniqueSheetTitle(string $base, array &$usedTitles): string
    {
        $invalid = ['\\', '/', '*', '?', ':', '[', ']'];
        $cleaned = str_replace($invalid, '', $base);
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'IAR';

        $candidate = mb_substr($cleaned, 0, 31);
        $i = 2;

        while (isset($usedTitles[$candidate])) {
            $suffix = '_'.$i;
            $maxBase = 31 - mb_strlen($suffix);
            $candidate = mb_substr($cleaned, 0, max(1, $maxBase)).$suffix;
            $i++;
        }

        $usedTitles[$candidate] = true;

        return $candidate;
    }

    protected function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        PhpExtensionGuard::ensureZipArchive();

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
