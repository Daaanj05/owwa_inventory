<?php

namespace App\Services;

use App\Models\PurchaseOrder;
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
 * Fast Appendix 61 PO Excel: PhpSpreadsheet lookalike using Fast PDF page data.
 */
class PurchaseOrderFastExcelExportService
{
    public function __construct(
        protected PurchaseOrderFastPdfExportService $fastPdfExport,
    ) {}

    public function download(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        $pages = $this->fastPdfExport->buildPages($purchaseOrder);
        abort_if($pages === [], 404, 'No purchase order pages could be built.');

        $spreadsheet = $this->makeSpreadsheet($pages);
        $filename = OwwaExportFilename::transaction(
            'PO-fast',
            $purchaseOrder->number ?? (string) $purchaseOrder->id,
            'xlsx',
        );

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * @param  Collection<int, PurchaseOrder>|iterable<int, PurchaseOrder>  $purchaseOrders
     */
    public function downloadMany(iterable $purchaseOrders): StreamedResponse
    {
        $pages = [];

        foreach ($purchaseOrders as $purchaseOrder) {
            array_push($pages, ...$this->fastPdfExport->buildPages($purchaseOrder));
        }

        abort_if($pages === [], 404, 'No purchase orders could be built for the selected date range.');

        return $this->streamXlsx(
            $this->makeSpreadsheet($pages),
            OwwaExportFilename::batch('PO-fast', ext: 'xlsx'),
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

            $titleBase = filled($page['po_no'] ?? null)
                ? (string) $page['po_no']
                : 'PO';
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

        // Column bands match Appendix 61 xls: A–F ≈ 13.4 / 13.9 / 35 / 12.9 / 13 / 16.9
        $sheet->getColumnDimension('A')->setWidth(13.4);
        $sheet->getColumnDimension('B')->setWidth(13.9);
        $sheet->getColumnDimension('C')->setWidth(35);
        $sheet->getColumnDimension('D')->setWidth(12.9);
        $sheet->getColumnDimension('E')->setWidth(13);
        $sheet->getColumnDimension('F')->setWidth(16.9);

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
        $sheet->setCellValue('C3', 'PURCHASE ORDER');
        $sheet->getStyle('C3')->applyFromArray([
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

        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'Entity Name: '.((string) ($page['entity_name'] ?? '')));
        $sheet->getStyle('A5')->applyFromArray($boldFont);
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A6:C6');
        $sheet->mergeCells('D6:F6');
        $sheet->setCellValue('A6', 'Supplier : '.((string) ($page['supplier'] ?? '')));
        $sheet->setCellValue('D6', 'P.O. No. : '.((string) ($page['po_no'] ?? '')));
        $sheet->getStyle('A6')->applyFromArray([
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_BOTTOM,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle('D6')->applyFromArray($boldFont);

        $sheet->mergeCells('A7:C7');
        $sheet->mergeCells('D7:F7');
        $sheet->setCellValue('A7', 'Address : '.((string) ($page['address'] ?? '')));
        $sheet->setCellValue('D7', 'Date : '.((string) ($page['date'] ?? '')));
        $sheet->getStyle('D7')->applyFromArray($boldFont);
        $sheet->getStyle('A7')->getAlignment()->setWrapText(false);

        $sheet->mergeCells('A8:C8');
        $sheet->mergeCells('D8:F8');
        $sheet->setCellValue('A8', 'TIN : '.((string) ($page['tin'] ?? '')));
        $sheet->setCellValue('D8', 'Mode of Procurement : '.((string) ($page['mode_of_procurement'] ?? '')));

        $sheet->mergeCells('A9:F9');
        $sheet->setCellValue('A9', 'Gentlemen:');
        $sheet->mergeCells('A10:F10');
        $sheet->setCellValue(
            'A10',
            'Please furnish this Office the following articles subject to the terms and conditions contained herein:',
        );

        $sheet->mergeCells('A11:C11');
        $sheet->mergeCells('D11:F11');
        $sheet->setCellValue('A11', 'Place of Delivery : '.((string) ($page['place_of_delivery'] ?? '')));
        $sheet->setCellValue('D11', 'Delivery Term : '.((string) ($page['delivery_term'] ?? '')));

        $sheet->mergeCells('A12:C12');
        $sheet->mergeCells('D12:F12');
        $sheet->setCellValue('A12', 'Date of Delivery : '.((string) ($page['date_of_delivery'] ?? '')));
        $sheet->setCellValue('D12', 'Payment Term : '.((string) ($page['payment_term'] ?? '')));

        $sheet->getStyle('A6:F12')->applyFromArray($thin);
        // Official header bands: no mid horizontals inside Supplier/TIN and Place/Date blocks;
        // keep the separator above Gentlemen (bottom of TIN row / top of Gentlemen).
        $none = Border::BORDER_NONE;
        foreach (['A6', 'B6', 'C6', 'D6', 'E6', 'F6'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getBottom()->setBorderStyle($none);
        }
        foreach (['A7', 'B7', 'C7', 'D7', 'E7', 'F7'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getTop()->setBorderStyle($none);
            $sheet->getStyle($addr)->getBorders()->getBottom()->setBorderStyle($none);
        }
        foreach (['A8', 'B8', 'C8', 'D8', 'E8', 'F8'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getTop()->setBorderStyle($none);
        }
        foreach (['A11', 'B11', 'C11', 'D11', 'E11', 'F11'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getBottom()->setBorderStyle($none);
        }
        foreach (['A12', 'B12', 'C12', 'D12', 'E12', 'F12'] as $addr) {
            $sheet->getStyle($addr)->getBorders()->getTop()->setBorderStyle($none);
        }

        $sheet->setCellValue('A13', "Stock/ Property\nNo.");
        $sheet->setCellValue('B13', 'Unit');
        $sheet->setCellValue('C13', 'Description');
        $sheet->setCellValue('D13', 'Quantity');
        $sheet->setCellValue('E13', 'Unit Cost');
        $sheet->setCellValue('F13', 'Amount');
        $headerStyle = [
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];
        $sheet->getStyle('A13:F13')->applyFromArray($headerStyle);
        $sheet->getStyle('A13:F13')->applyFromArray($thin);
        $sheet->getRowDimension(13)->setRowHeight(28);

        $lines = $page['lines'] ?? [];
        $maxRows = $this->fastPdfExport->maxRowsPerPage();
        $startRow = 14;
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
            $sheet->setCellValue('F'.$row, $line['amount'] ?? '');
        }

        $sheet->getStyle('A'.$startRow.':F'.$endRow)->applyFromArray($thin);
        $sheet->getStyle('A'.$startRow.':F'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('C'.$startRow.':C'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('E'.$startRow.':F'.$endRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $totalRow = $endRow + 1;
        $sheet->mergeCells('A'.$totalRow.':E'.$totalRow);
        $words = $includeFooter ? (string) ($page['total_amount_in_words'] ?? '') : '';
        $sheet->setCellValue('A'.$totalRow, '(Total Amount in Words) '.$words);
        $sheet->setCellValue('F'.$totalRow, $includeFooter ? (string) ($page['total_amount_formatted'] ?? '') : '');
        $sheet->getStyle('A'.$totalRow.':F'.$totalRow)->applyFromArray($thin);
        $sheet->getStyle('F'.$totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('F'.$totalRow)->applyFromArray($boldFont);

        $penaltyRow = $totalRow + 1;
        $sheet->mergeCells('A'.$penaltyRow.':F'.$penaltyRow);
        $sheet->setCellValue(
            'A'.$penaltyRow,
            'In case of failure to make the full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for every day of delay shall be imposed on the undelivered item/s.',
        );
        $sheet->getStyle('A'.$penaltyRow)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($penaltyRow)->setRowHeight(30);
        $sheet->getStyle('A'.$penaltyRow.':F'.$penaltyRow)->applyFromArray($thin);

        $sigHead = $penaltyRow + 1;
        $sheet->mergeCells('A'.$sigHead.':C'.$sigHead);
        $sheet->mergeCells('D'.$sigHead.':F'.$sigHead);
        $sheet->setCellValue('A'.$sigHead, 'Conforme:');
        $sheet->setCellValue('D'.$sigHead, 'Very truly yours,');
        $sheet->getStyle('A'.$sigHead)->applyFromArray($boldFont);
        $sheet->getStyle('D'.$sigHead)->applyFromArray($boldFont);

        $sigLine = $sigHead + 1;
        $sheet->mergeCells('A'.$sigLine.':C'.$sigLine);
        $sheet->mergeCells('D'.$sigLine.':F'.$sigLine);
        $supplierName = trim((string) ($page['supplier'] ?? ''));
        $sheet->setCellValue('A'.$sigLine, $supplierName !== '' ? $supplierName : '__________________________');
        $sheet->setCellValue('D'.$sigLine, '________________________________');
        $sheet->getStyle('A'.$sigLine.':F'.$sigLine)->applyFromArray([
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $cap1 = $sigLine + 1;
        $sheet->mergeCells('A'.$cap1.':C'.$cap1);
        $sheet->mergeCells('D'.$cap1.':F'.$cap1);
        $sheet->setCellValue('A'.$cap1, 'Signature over Printed Name of Supplier');
        $sheet->setCellValue('D'.$cap1, 'Signature over Printed Name of Authorized Official');

        $dateLine = $cap1 + 1;
        $sheet->mergeCells('A'.$dateLine.':C'.$dateLine);
        $sheet->mergeCells('D'.$dateLine.':F'.$dateLine);
        $sheet->setCellValue('A'.$dateLine, '___________________________');
        $sheet->setCellValue('D'.$dateLine, '_____________________________');
        $sheet->getStyle('A'.$dateLine.':F'.$dateLine)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $cap2 = $dateLine + 1;
        $sheet->mergeCells('A'.$cap2.':C'.$cap2);
        $sheet->mergeCells('D'.$cap2.':F'.$cap2);
        $sheet->setCellValue('A'.$cap2, 'Date');
        $sheet->setCellValue('D'.$cap2, 'Designation');

        $acct1 = $cap2 + 1;
        $sheet->mergeCells('A'.$acct1.':C'.$acct1);
        $sheet->mergeCells('D'.$acct1.':F'.$acct1);
        $sheet->setCellValue('A'.$acct1, 'Fund Cluster : ___________________________________');
        $sheet->setCellValue('D'.$acct1, 'ORS/BURS No. : ______________________');

        $acct2 = $acct1 + 1;
        $sheet->mergeCells('A'.$acct2.':C'.$acct2);
        $sheet->mergeCells('D'.$acct2.':F'.$acct2);
        $sheet->setCellValue('A'.$acct2, 'Funds Available : _________________________________');
        $sheet->setCellValue('D'.$acct2, 'Date of the ORS/BURS: _______________');

        $acct3 = $acct2 + 1;
        $sheet->mergeCells('A'.$acct3.':C'.$acct3);
        $sheet->mergeCells('D'.$acct3.':F'.$acct3);
        $sheet->setCellValue(
            'A'.$acct3,
            "__________________________\nSignature over Printed Name of Chief Accountant/Head of Accounting Division/Unit",
        );
        $sheet->setCellValue('D'.$acct3, 'Amount : ____________________________');

        $sheet->getStyle('A'.$sigHead.':F'.$acct3)->applyFromArray($thin);
        // Conforme / Very truly yours: outer + vertical only between stacked signature rows.
        foreach (range($sigHead, $cap2) as $row) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                $sheet->getStyle($col.$row)->getBorders()->getTop()->setBorderStyle($none);
                $sheet->getStyle($col.$row)->getBorders()->getBottom()->setBorderStyle($none);
            }
        }
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getStyle($col.$sigHead)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($col.$cap2)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        // Accounting box: Fund Cluster / Funds Available share one band (no mid horizontal).
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getStyle($col.$acct1)->getBorders()->getBottom()->setBorderStyle($none);
            $sheet->getStyle($col.$acct2)->getBorders()->getTop()->setBorderStyle($none);
            $sheet->getStyle($col.$acct2)->getBorders()->getBottom()->setBorderStyle($none);
            $sheet->getStyle($col.$acct3)->getBorders()->getTop()->setBorderStyle($none);
        }
        $sheet->getStyle('A'.$cap1.':F'.$cap2)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.$acct3)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageMargins()->setLeft(0.55);
        $sheet->getPageMargins()->setRight(0.55);
        $sheet->getPageMargins()->setTop(0.5);
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
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'PO';

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
