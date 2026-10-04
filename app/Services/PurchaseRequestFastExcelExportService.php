<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
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
 * Fast Appendix 60 PR Excel: PhpSpreadsheet lookalike using Fast PDF page data.
 */
class PurchaseRequestFastExcelExportService
{
    public function __construct(
        protected PurchaseRequestFastPdfExportService $fastPdfExport,
    ) {}

    public function download(AcquisitionPaperwork $paperwork): StreamedResponse
    {
        $pages = $this->fastPdfExport->buildPages($paperwork);
        abort_if($pages === [], 404, 'No purchase request pages could be built.');

        $spreadsheet = $this->makeSpreadsheet($pages);
        $filename = OwwaExportFilename::transaction(
            'PR-fast',
            $paperwork->pr_number ?? (string) $paperwork->id,
            'xlsx',
        );

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * @param  Collection<int, AcquisitionPaperwork>|iterable<int, AcquisitionPaperwork>  $paperworks
     */
    public function downloadMany(iterable $paperworks): StreamedResponse
    {
        $pages = [];

        foreach ($paperworks as $paperwork) {
            array_push($pages, ...$this->fastPdfExport->buildPages($paperwork));
        }

        abort_if($pages === [], 404, 'No purchase requests could be built for the selected date range.');

        return $this->streamXlsx(
            $this->makeSpreadsheet($pages),
            OwwaExportFilename::batch('PR-fast', ext: 'xlsx'),
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

            $titleBase = filled($page['pr_no'] ?? null)
                ? (string) $page['pr_no']
                : 'PR';
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

        // Column bands match Appendix 60 xls/HTML: Date E–F ≈ Unit Cost + Total Cost (~9.2% + ~13.1%).
        $sheet->getColumnDimension('A')->setWidth(15.3);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(19.3);
        $sheet->getColumnDimension('D')->setWidth(25.5);
        $sheet->getColumnDimension('E')->setWidth(9.1);
        $sheet->getColumnDimension('F')->setWidth(13);

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
        $sheet->mergeCells('C1:E1');
        $sheet->setCellValue('C1', 'Republic of the Philippines');
        $sheet->getStyle('C1')->applyFromArray($agencyStyle);

        $sheet->mergeCells('C2:E2');
        $sheet->setCellValue('C2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION');
        $sheet->getStyle('C2')->applyFromArray($agencyStyle);

        $sheet->mergeCells('C3:E3');
        $sheet->setCellValue('C3', 'PURCHASE REQUEST');
        $sheet->getStyle('C3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $this->addHeaderLogos($sheet);

        $boldFont = ['font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11]];

        $sheet->setCellValue('A5', 'Entity Name: '.((string) ($page['entity_name'] ?? '')));
        $sheet->setCellValue('D5', 'Fund Cluster: ');
        $sheet->mergeCells('A5:C5');
        $sheet->mergeCells('D5:F5');
        $sheet->getStyle('A5')->applyFromArray($boldFont);
        $sheet->getStyle('D5')->applyFromArray($boldFont);

        $thin = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ];
        $outlineOnly = [
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM],
            ],
        ];
        $medium = Border::BORDER_MEDIUM;
        $none = Border::BORDER_NONE;

        $sheet->mergeCells('A6:B6');
        $sheet->mergeCells('C6:D6');
        $sheet->mergeCells('E6:F7');
        $sheet->mergeCells('A7:B7');
        $sheet->mergeCells('C7:D7');

        $sheet->setCellValue('A6', 'Office/Section : _____________');
        $sheet->setCellValue('C6', 'PR No.: '.((string) ($page['pr_no'] ?? '')));
        $sheet->setCellValue('E6', 'Date: '.((string) ($page['date'] ?? '')));
        $sheet->setCellValue('A7', (string) ($page['office_section'] ?? ''));
        $sheet->setCellValue('C7', 'Responsibility Center Code : '.((string) ($page['responsibility_center_code'] ?? '')));

        $sheet->getStyle('C6')->applyFromArray($boldFont);
        $sheet->getStyle('E6')->applyFromArray($boldFont);
        $sheet->getStyle('C7')->applyFromArray($boldFont);
        $sheet->getStyle('E6')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // Official header: outer + vertical separators only — no mid line between Office/PR rows.
        foreach (['A6', 'B6', 'C6', 'D6', 'E6', 'F6', 'A7', 'B7', 'C7', 'D7', 'E7', 'F7'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getTop()->setBorderStyle($none);
            $sheet->getStyle($addr)->getBorders()->getBottom()->setBorderStyle($none);
            $sheet->getStyle($addr)->getBorders()->getLeft()->setBorderStyle($none);
            $sheet->getStyle($addr)->getBorders()->getRight()->setBorderStyle($none);
        }
        foreach (['A6', 'B6', 'C6', 'D6', 'E6', 'F6'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getTop()->setBorderStyle($medium);
        }
        foreach (['A7', 'B7', 'C7', 'D7', 'E7', 'F7'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getBottom()->setBorderStyle($medium);
        }
        $sheet->getStyle('A6')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('A7')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('B6')->getBorders()->getRight()->setBorderStyle($medium);
        $sheet->getStyle('B7')->getBorders()->getRight()->setBorderStyle($medium);
        $sheet->getStyle('C6')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('C7')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('D6')->getBorders()->getRight()->setBorderStyle($medium);
        $sheet->getStyle('D7')->getBorders()->getRight()->setBorderStyle($medium);
        $sheet->getStyle('E6')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('E7')->getBorders()->getLeft()->setBorderStyle($medium);
        $sheet->getStyle('F6')->getBorders()->getRight()->setBorderStyle($medium);
        $sheet->getStyle('F7')->getBorders()->getRight()->setBorderStyle($medium);

        $sheet->mergeCells('A8:A9');
        $sheet->setCellValue('A8', "Stock/ Property\nNo.");
        $sheet->setCellValue('B8', 'Unit');
        $sheet->setCellValue('C8', 'Item Description');
        $sheet->setCellValue('D8', 'Quantity');
        $sheet->setCellValue('E8', 'Unit Cost');
        $sheet->setCellValue('F8', 'Total Cost');
        $sheet->mergeCells('B8:B9');
        $sheet->mergeCells('C8:C9');
        $sheet->mergeCells('D8:D9');
        $sheet->mergeCells('E8:E9');
        $sheet->mergeCells('F8:F9');

        $headerStyle = [
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];
        $sheet->getStyle('A8:F9')->applyFromArray($headerStyle);
        $sheet->getStyle('A8:F9')->applyFromArray($thin);
        $sheet->getRowDimension(8)->setRowHeight(16);
        $sheet->getRowDimension(9)->setRowHeight(16);

        $lines = $page['lines'] ?? [];
        $maxRows = $this->fastPdfExport->maxRowsPerPage();
        $startRow = 10;
        $endRow = $startRow + $maxRows - 1;

        foreach ($lines as $index => $line) {
            $row = $startRow + $index;
            if ($row > $endRow) {
                break;
            }

            $sheet->setCellValue('A'.$row, $line['stock_no'] ?? '');
            $sheet->setCellValue('B'.$row, $line['unit'] ?? '');
            $sheet->setCellValue('C'.$row, $line['description'] ?? '');
            $sheet->setCellValue('D'.$row, $line['quantity'] ?? '');
            $sheet->setCellValue('E'.$row, $line['unit_cost'] ?? '');
            $sheet->setCellValue('F'.$row, $line['total_cost'] ?? '');
        }

        $sheet->getStyle('A'.$startRow.':F'.$endRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$startRow.':F'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('C'.$startRow.':C'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $purposeText = $includeFooter ? (string) ($page['purpose'] ?? '') : '';
        $purposeRow = $endRow + 1;
        $sheet->mergeCells('A'.$purposeRow.':F'.$purposeRow);
        $firstLine = 'Purpose: '.$purposeText;
        $underline = str_repeat('_', 72);
        $sheet->setCellValue(
            'A'.$purposeRow,
            $firstLine."\n".$underline."\n".$underline,
        );
        $sheet->getStyle('A'.$purposeRow)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($purposeRow)->setRowHeight(52);
        $sheet->getStyle('A'.$purposeRow.':F'.$purposeRow)->applyFromArray($outlineOnly);

        $sigHead = $purposeRow + 1;
        $sheet->mergeCells('B'.$sigHead.':C'.$sigHead);
        $sheet->mergeCells('D'.$sigHead.':F'.$sigHead);
        $sheet->setCellValue('B'.$sigHead, 'Requested by:');
        $sheet->setCellValue('D'.$sigHead, 'Approved by:');
        $sheet->getStyle('B'.$sigHead)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D'.$sigHead)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sigRow = $sigHead + 1;
        $sheet->setCellValue('A'.$sigRow, 'Signature :');
        $sheet->mergeCells('B'.$sigRow.':C'.$sigRow);
        $sheet->mergeCells('D'.$sigRow.':F'.$sigRow);

        $nameRow = $sigRow + 1;
        $sheet->setCellValue('A'.$nameRow, 'Printed Name :');
        $sheet->mergeCells('B'.$nameRow.':C'.$nameRow);
        $sheet->mergeCells('D'.$nameRow.':F'.$nameRow);
        if ($includeFooter) {
            $sheet->setCellValue('B'.$nameRow, (string) ($page['requested_by_name'] ?? ''));
            $sheet->setCellValue('D'.$nameRow, (string) ($page['approved_by_name'] ?? ''));
        }

        $desigRow = $nameRow + 1;
        $sheet->setCellValue('A'.$desigRow, 'Designation :');
        $sheet->mergeCells('B'.$desigRow.':C'.$desigRow);
        $sheet->mergeCells('D'.$desigRow.':F'.$desigRow);
        if ($includeFooter) {
            $sheet->setCellValue('B'.$desigRow, (string) ($page['requested_by_designation'] ?? ''));
            $sheet->setCellValue('D'.$desigRow, (string) ($page['approved_by_designation'] ?? ''));
        }

        $sheet->getStyle('A'.$sigHead.':F'.$desigRow)->applyFromArray([
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM],
                'inside' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
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
            $owwa->setCoordinates('B1');
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
            $bagong->setCoordinates('F1');
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
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'PR';

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
