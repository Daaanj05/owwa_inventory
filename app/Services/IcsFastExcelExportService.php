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
 * Fast Appendix 59 ICS Excel: PhpSpreadsheet lookalike using Fast PDF page data.
 */
class IcsFastExcelExportService
{
    public function __construct(
        protected IcsFastPdfExportService $fastPdfExport,
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
        abort_if($pages === [], 404, 'No inventory custodian slip pages could be built.');

        $spreadsheet = $this->makeSpreadsheet($pages);
        $filename = OwwaExportFilename::transaction(
            'ICS-fast',
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

        abort_if($pages === [], 404, 'No inventory custodian slips could be built.');

        return $this->streamXlsx(
            $this->makeSpreadsheet($pages),
            OwwaExportFilename::batch('ICS-fast', ext: 'xlsx'),
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

            $titleBase = filled($page['ics_no'] ?? null)
                ? (string) $page['ics_no']
                : 'ICS';
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

        $sheet->getColumnDimension('A')->setWidth(9.33203125);
        $sheet->getColumnDimension('B')->setWidth(6.5546875);
        $sheet->getColumnDimension('C')->setWidth(8.5546875);
        $sheet->getColumnDimension('D')->setWidth(12.6640625);
        $sheet->getColumnDimension('E')->setWidth(14.6640625);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(13);
        $sheet->getColumnDimension('H')->setWidth(14.33203125);

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
        $sheet->mergeCells('C1:F1');
        $sheet->setCellValue('C1', 'Republic of the Philippines');
        $sheet->getStyle('C1')->applyFromArray($agencyStyle);

        $sheet->mergeCells('C2:F2');
        $sheet->setCellValue('C2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION');
        $sheet->getStyle('C2')->applyFromArray($agencyStyle);

        $sheet->mergeCells('C3:F3');
        $sheet->setCellValue('C3', 'INVENTORY CUSTODIAN SLIP');
        $sheet->getStyle('C3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $this->addHeaderLogos($sheet);

        $metaBold = ['font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 10]];

        // Official Appendix 59: Entity/Fund underlines end around cols B–C; ICS No at G + line on H.
        $sheet->mergeCells('A5:C5');
        $sheet->setCellValue('A5', 'Entity Name: '.((string) ($page['entity_name'] ?? '')));
        $sheet->mergeCells('A6:C6');
        $sheet->setCellValue('A6', 'Fund Cluster : '.((string) ($page['fund_cluster'] ?? '')));
        $sheet->setCellValue('G6', 'ICS No :');
        $sheet->setCellValue('H6', (string) ($page['ics_no'] ?? ''));
        $sheet->getStyle('A5')->applyFromArray($metaBold);
        $sheet->getStyle('A6')->applyFromArray($metaBold);
        $sheet->getStyle('G6')->applyFromArray($metaBold);
        $sheet->getStyle('H6')->applyFromArray($metaBold);
        $sheet->getStyle('A5:C5')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A6:C6')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('H6')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('G6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('H6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

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

        $sheet->mergeCells('A8:A9');
        $sheet->mergeCells('B8:B9');
        $sheet->mergeCells('C8:D8');
        $sheet->mergeCells('E8:F9');
        $sheet->mergeCells('G8:G9');
        $sheet->mergeCells('H8:H9');

        $sheet->setCellValue('A8', 'Quantity');
        $sheet->setCellValue('B8', 'Unit');
        $sheet->setCellValue('C8', 'Amount');
        $sheet->setCellValue('C9', 'Unit Cost');
        $sheet->setCellValue('D9', 'Total Cost');
        $sheet->setCellValue('E8', 'Description');
        $sheet->setCellValue('G8', 'Inventory Item No.');
        $sheet->setCellValue('H8', 'Estimated Useful Life');

        $sheet->getStyle('A8:H9')->applyFromArray($headerStyle);
        $sheet->getStyle('A8:H9')->applyFromArray($thin);
        $sheet->getRowDimension(8)->setRowHeight(16);
        $sheet->getRowDimension(9)->setRowHeight(16);

        $lines = $page['lines'] ?? [];
        $minRows = max($this->fastPdfExport->minDetailRows(), count($lines));
        $startRow = 10;
        $endRow = $startRow + $minRows - 1;

        foreach ($lines as $index => $line) {
            $row = $startRow + $index;
            $sheet->mergeCells('E'.$row.':F'.$row);
            $sheet->setCellValue('A'.$row, $line['quantity'] ?? '');
            $sheet->setCellValue('B'.$row, $line['unit'] ?? '');
            $sheet->setCellValue('C'.$row, $line['unit_cost'] ?? '');
            $sheet->setCellValue('D'.$row, $line['total_cost'] ?? '');
            $sheet->setCellValue('E'.$row, $line['description'] ?? '');
            $sheet->setCellValue('G'.$row, $line['inventory_item_no'] ?? '');
            $sheet->setCellValue('H'.$row, $line['estimated_useful_life'] ?? '');
        }

        for ($row = $startRow + count($lines); $row <= $endRow; $row++) {
            $sheet->mergeCells('E'.$row.':F'.$row);
        }

        $sheet->getStyle('A'.$startRow.':H'.$endRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$startRow.':H'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Flush under the detail grid so the shared edge stays one thin line (not table bottom + sig top).
        $sigHead = $endRow + 1;
        $sheet->mergeCells('A'.$sigHead.':D'.$sigHead);
        $sheet->mergeCells('E'.$sigHead.':H'.$sigHead);
        $sheet->setCellValue('A'.$sigHead, 'Received from:');
        $sheet->setCellValue('E'.$sigHead, 'Received by:');
        $sheet->getStyle('A'.$sigHead)->getFont()->setBold(true);
        $sheet->getStyle('E'.$sigHead)->getFont()->setBold(true);

        $nameRow = $sigHead + 1;
        $nameCaption = $nameRow + 1;
        $posRow = $nameCaption + 1;
        $posCaption = $posRow + 1;
        $dateRow = $posCaption + 1;
        $dateCaption = $dateRow + 1;

        $sheet->mergeCells('A'.$nameRow.':D'.$nameRow);
        $sheet->mergeCells('E'.$nameRow.':H'.$nameRow);
        $sheet->setCellValue('A'.$nameRow, (string) ($page['received_from_name'] ?? ''));
        $sheet->setCellValue('E'.$nameRow, (string) ($page['received_by_name'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$nameRow, (string) ($page['received_from_name'] ?? ''));
        $this->underlineSignatureValue($sheet, 'E'.$nameRow, (string) ($page['received_by_name'] ?? ''));

        $sheet->mergeCells('A'.$nameCaption.':D'.$nameCaption);
        $sheet->mergeCells('E'.$nameCaption.':H'.$nameCaption);
        $sheet->setCellValue('A'.$nameCaption, 'Signature Over Printed Name');
        $sheet->setCellValue('E'.$nameCaption, 'Signature Over Printed Name');

        $sheet->mergeCells('A'.$posRow.':D'.$posRow);
        $sheet->mergeCells('E'.$posRow.':H'.$posRow);
        $sheet->setCellValue('A'.$posRow, (string) ($page['received_from_position'] ?? ''));
        $sheet->setCellValue('E'.$posRow, (string) ($page['received_by_position'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$posRow, (string) ($page['received_from_position'] ?? ''));
        $this->underlineSignatureValue($sheet, 'E'.$posRow, (string) ($page['received_by_position'] ?? ''));

        $sheet->mergeCells('A'.$posCaption.':D'.$posCaption);
        $sheet->mergeCells('E'.$posCaption.':H'.$posCaption);
        $sheet->setCellValue('A'.$posCaption, 'Position/Office');
        $sheet->setCellValue('E'.$posCaption, 'Position/Office');

        $sheet->mergeCells('A'.$dateRow.':D'.$dateRow);
        $sheet->mergeCells('E'.$dateRow.':H'.$dateRow);
        $sheet->setCellValue('A'.$dateRow, (string) ($page['received_from_date'] ?? ''));
        $sheet->setCellValue('E'.$dateRow, (string) ($page['received_by_date'] ?? ''));
        $this->underlineSignatureValue($sheet, 'A'.$dateRow, (string) ($page['received_from_date'] ?? ''));
        $this->underlineSignatureValue($sheet, 'E'.$dateRow, (string) ($page['received_by_date'] ?? ''));

        $sheet->mergeCells('A'.$dateCaption.':D'.$dateCaption);
        $sheet->mergeCells('E'.$dateCaption.':H'.$dateCaption);
        $sheet->setCellValue('A'.$dateCaption, 'Date');
        $sheet->setCellValue('E'.$dateCaption, 'Date');

        $captionStyle = [
            'font' => ['size' => 8, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_TOP,
            ],
        ];
        $valueStyle = [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ];
        $sheet->getStyle('A'.$nameRow.':H'.$dateRow)->applyFromArray($valueStyle);
        $sheet->getStyle('A'.$nameCaption.':H'.$dateCaption)->applyFromArray($captionStyle);

        // Same thin weight as the detail table grid. Skip a second top edge — the last
        // detail row already drew that shared line.
        foreach (range($sigHead, $dateCaption) as $row) {
            $sheet->getStyle('A'.$row)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('H'.$row)->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('E'.$row)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
        }
        $sheet->getStyle('A'.$dateCaption.':H'.$dateCaption)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:H'.$dateCaption);
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
            $bagong->setCoordinates('G1');
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
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'ICS';

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
