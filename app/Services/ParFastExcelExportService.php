<?php

namespace App\Services;

use App\Models\Issuance;
use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fast Appendix 71 PAR Excel: PhpSpreadsheet lookalike using Fast PDF page data.
 */
class ParFastExcelExportService
{
    public function __construct(
        protected ParFastPdfExportService $fastPdfExport,
    ) {}

    public function download(Issuance $issuance): StreamedResponse
    {
        $issuance->loadMissing([
            'item.category',
            'office',
            'department',
            'issuedBy.office',
            'issuedTo.office',
            'issuedTo.department',
            'requisition',
            'consolidatedRequisition',
            'batch.lines.item',
            'batch.lines.office',
            'batch.lines.department',
            'batch.lines.issuedBy',
            'batch.lines.issuedTo',
            'batch.issuedTo',
        ]);

        $pages = $this->fastPdfExport->buildPages($issuance->batchLines());
        abort_if($pages === [], 404, 'No property acknowledgment receipt pages could be built.');

        $spreadsheet = $this->makeSpreadsheet($pages);
        $filename = OwwaExportFilename::transaction(
            'PAR-fast',
            $issuance->controlNumber() ?? (string) $issuance->getKey(),
            'xlsx',
        );

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * @param  Collection<int, Issuance>|iterable<int, Issuance>  $issuances
     */
    public function downloadMany(iterable $issuances): StreamedResponse
    {
        $pages = [];

        foreach ($issuances as $issuance) {
            array_push($pages, ...$this->fastPdfExport->buildPages(collect([$issuance])));
        }

        abort_if($pages === [], 404, 'No property acknowledgment receipts could be built.');

        return $this->streamXlsx(
            $this->makeSpreadsheet($pages),
            OwwaExportFilename::batch('PAR-fast', ext: 'xlsx'),
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

            $titleBase = filled($page['par_no'] ?? null)
                ? (string) $page['par_no']
                : 'PAR';
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
            ->setSize(10);

        $sheet->getColumnDimension('A')->setWidth(10.109375);
        $sheet->getColumnDimension('B')->setWidth(10.109375);
        $sheet->getColumnDimension('C')->setWidth(29.44140625);
        $sheet->getColumnDimension('D')->setWidth(13.44140625);
        $sheet->getColumnDimension('E')->setWidth(13.44140625);
        $sheet->getColumnDimension('F')->setWidth(17.44140625);

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
        $sheet->mergeCells('B1:E1');
        $sheet->setCellValue('B1', 'Republic of the Philippines');
        $sheet->getStyle('B1')->applyFromArray($agencyStyle);

        $sheet->mergeCells('B2:E2');
        $sheet->setCellValue('B2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION');
        $sheet->getStyle('B2')->applyFromArray($agencyStyle);

        $sheet->mergeCells('B3:E3');
        $sheet->setCellValue('B3', 'PROPERTY ACKNOWLEDGMENT RECEIPT');
        $sheet->getStyle('B3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $this->addHeaderLogos($sheet);

        $metaBold = ['font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 10]];

        // Official Appendix 71: Entity/Fund underlines through col D; PAR No at E + line on F.
        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', 'Entity Name : '.((string) ($page['entity_name'] ?? '')));
        $sheet->mergeCells('A6:D6');
        $sheet->setCellValue('A6', 'Fund Cluster: '.((string) ($page['fund_cluster'] ?? '')));
        $sheet->setCellValue('E6', 'PAR No.:');
        $sheet->setCellValue('F6', (string) ($page['par_no'] ?? ''));
        $sheet->getStyle('A5')->applyFromArray($metaBold);
        $sheet->getStyle('A6')->applyFromArray($metaBold);
        $sheet->getStyle('E6')->applyFromArray($metaBold);
        $sheet->getStyle('F6')->applyFromArray($metaBold);
        $sheet->getStyle('A5:D5')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A6:D6')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('F6')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('E6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('F6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $thin = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ];
        $headerStyle = [
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];

        $sheet->setCellValue('A8', 'Quantity');
        $sheet->setCellValue('B8', 'Unit');
        $sheet->setCellValue('C8', 'Description');
        $sheet->setCellValue('D8', "Property\nNumber");
        $sheet->setCellValue('E8', "Date\nAcquired");
        $sheet->setCellValue('F8', 'Amount');
        $sheet->getStyle('A8:F8')->applyFromArray($headerStyle);
        $sheet->getStyle('A8:F8')->applyFromArray($thin);
        $sheet->getRowDimension(8)->setRowHeight(28);

        $lines = $page['lines'] ?? [];
        $minRows = max($this->fastPdfExport->minDetailRows(), count($lines));
        $startRow = 9;
        $endRow = $startRow + $minRows - 1;

        foreach ($lines as $index => $line) {
            $row = $startRow + $index;
            $sheet->setCellValue('A'.$row, $line['quantity'] ?? '');
            $sheet->setCellValue('B'.$row, $line['unit'] ?? '');
            $sheet->setCellValue('C'.$row, $line['description'] ?? '');
            $sheet->setCellValue('D'.$row, $line['property_number'] ?? '');
            $sheet->setCellValue('E'.$row, $line['date_acquired'] ?? '');
            $sheet->setCellValue('F'.$row, $line['amount'] ?? '');
        }

        $sheet->getStyle('A'.$startRow.':F'.$endRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$startRow.':F'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sigHead = $endRow + 1;
        $sheet->mergeCells('A'.$sigHead.':C'.$sigHead);
        $sheet->mergeCells('D'.$sigHead.':F'.$sigHead);
        $sheet->setCellValue('A'.$sigHead, 'Received by:');
        $sheet->setCellValue('D'.$sigHead, 'Issued by:');
        $sheet->getStyle('A'.$sigHead)->getFont()->setBold(true);
        $sheet->getStyle('D'.$sigHead)->getFont()->setBold(true);

        $nameRow = $sigHead + 1;
        $nameCaption = $nameRow + 1;
        $posRow = $nameCaption + 1;
        $posCaption = $posRow + 1;
        $dateRow = $posCaption + 1;
        $dateCaption = $dateRow + 1;

        $sheet->mergeCells('A'.$nameRow.':C'.$nameRow);
        $sheet->mergeCells('D'.$nameRow.':F'.$nameRow);
        $sheet->setCellValue('A'.$nameRow, (string) ($page['received_by_name'] ?? ''));
        $sheet->setCellValue('D'.$nameRow, (string) ($page['issued_by_name'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$nameRow, (string) ($page['received_by_name'] ?? ''));
        $this->underlineSignatureValue($sheet, 'D'.$nameRow, (string) ($page['issued_by_name'] ?? ''));

        $sheet->mergeCells('A'.$nameCaption.':C'.$nameCaption);
        $sheet->mergeCells('D'.$nameCaption.':F'.$nameCaption);
        $sheet->setCellValue('A'.$nameCaption, 'Signature over Printed Name of End-User');
        $sheet->setCellValue('D'.$nameCaption, 'Signature over Printed Name of Supply and/or Property Custodian');

        $sheet->mergeCells('A'.$posRow.':C'.$posRow);
        $sheet->mergeCells('D'.$posRow.':F'.$posRow);
        $sheet->setCellValue('A'.$posRow, (string) ($page['received_by_position'] ?? ''));
        $sheet->setCellValue('D'.$posRow, (string) ($page['issued_by_position'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$posRow, (string) ($page['received_by_position'] ?? ''));
        $this->underlineSignatureValue($sheet, 'D'.$posRow, (string) ($page['issued_by_position'] ?? ''));

        $sheet->mergeCells('A'.$posCaption.':C'.$posCaption);
        $sheet->mergeCells('D'.$posCaption.':F'.$posCaption);
        $sheet->setCellValue('A'.$posCaption, 'Position/Office');
        $sheet->setCellValue('D'.$posCaption, 'Position/Office');

        $sheet->mergeCells('A'.$dateRow.':C'.$dateRow);
        $sheet->mergeCells('D'.$dateRow.':F'.$dateRow);
        $sheet->setCellValue('A'.$dateRow, (string) ($page['received_by_date'] ?? ''));
        $sheet->setCellValue('D'.$dateRow, (string) ($page['issued_by_date'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$dateRow, (string) ($page['received_by_date'] ?? ''));
        $this->underlineSignatureValue($sheet, 'D'.$dateRow, (string) ($page['issued_by_date'] ?? ''));

        $sheet->mergeCells('A'.$dateCaption.':C'.$dateCaption);
        $sheet->mergeCells('D'.$dateCaption.':F'.$dateCaption);
        $sheet->setCellValue('A'.$dateCaption, 'Date');
        $sheet->setCellValue('D'.$dateCaption, 'Date');

        $captionStyle = [
            'font' => ['size' => 8, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ];
        $valueStyle = [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ];
        $sheet->getStyle('A'.$nameRow.':F'.$dateRow)->applyFromArray($valueStyle);
        $sheet->getStyle('A'.$nameCaption.':F'.$dateCaption)->applyFromArray($captionStyle);
        $sheet->getRowDimension($nameCaption)->setRowHeight(28);

        foreach (range($sigHead, $dateCaption) as $row) {
            $sheet->getStyle('A'.$row)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('F'.$row)->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('D'.$row)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
        }
        $sheet->getStyle('A'.$dateCaption.':F'.$dateCaption)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:F'.$dateCaption);
        // Stock Card Fast default margins (14/12/12/12 mm).
        $sheet->getPageMargins()->setTop(0.55);
        $sheet->getPageMargins()->setRight(0.47);
        $sheet->getPageMargins()->setBottom(0.47);
        $sheet->getPageMargins()->setLeft(0.47);
    }

    protected function underlineSignatureValue(Worksheet $sheet, string $cell, string $value): void
    {
        if ($value === '') {
            $sheet->getStyle($cell)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

            return;
        }

        $sheet->getStyle($cell)->getFont()->setUnderline(Font::UNDERLINE_SINGLE);
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
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'PAR';

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
