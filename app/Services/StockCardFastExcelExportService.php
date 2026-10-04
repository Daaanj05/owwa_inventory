<?php

namespace App\Services;

use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fast Stock Card Excel: reuses Fast PDF card data, builds a plain PhpSpreadsheet
 * lookalike (not the official Appendix 58 template clone).
 */
class StockCardFastExcelExportService
{
    public const int MIN_LEDGER_ROWS = 28;

    public function __construct(
        protected StockCardFastPdfExportService $fastPdfExport,
    ) {}

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    public function download(
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): StreamedResponse {
        $cards = $this->fastPdfExport->buildCards($pairs, $dateFrom, $dateTo);

        abort_if($cards === [], 404, 'No matching stock cards could be built for the selected positions.');

        $spreadsheet = $this->makeSpreadsheet($cards);
        $filename = OwwaExportFilename::batch('SC-fast', ext: 'xlsx');

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * @param  array<int, array{
     *     item_name: string,
     *     item_code: string,
     *     description: string,
     *     unit: string,
     *     reorder_level: int|float|string|null,
     *     days_to_consume: int|float|string|null,
     *     office_name: string,
     *     transactions: array<int, array<string, mixed>>
     * }>  $cards
     */
    public function makeSpreadsheet(array $cards): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $usedTitles = [];
        $first = true;

        foreach ($cards as $card) {
            $sheet = $first
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $first = false;

            $titleBase = filled($card['item_code'] ?? null)
                ? (string) $card['item_code']
                : 'SC';
            $sheet->setTitle($this->uniqueSheetTitle($titleBase, $usedTitles));
            $this->fillCardSheet($sheet, $card);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array{
     *     item_name: string,
     *     item_code: string,
     *     description: string,
     *     unit: string,
     *     reorder_level: int|float|string|null,
     *     days_to_consume: int|float|string|null,
     *     office_name: string,
     *     transactions: array<int, array<string, mixed>>
     * }  $card
     */
    protected function fillCardSheet(Worksheet $sheet, array $card): void
    {
        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $entityLine = filled($card['office_name'] ?? null)
            ? (string) $card['office_name']
            : $entityName;

        $sheet->getParent()?->getDefaultStyle()->getFont()
            ->setName('Times New Roman')
            ->setSize(12);

        // Rows 1–3: OWWA left | agency lines + STOCK CARD | Bagong right (logo 2.33cm × 2.5cm)
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
        $sheet->setCellValue('C3', 'STOCK CARD');
        $sheet->getStyle('C3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_BOTTOM,
            ],
        ]);
        $this->addHeaderLogos($sheet);

        $sheet->setCellValue('A5', 'Entity Name: '.$entityLine);
        $sheet->setCellValue('F5', 'Fund Cluster: ');
        $sheet->getStyle('A5')->getFont()->setBold(true);
        $sheet->getStyle('F5')->getFont()->setBold(true);

        $sheet->setCellValue('A6', 'Item : '.$card['item_name']);
        $sheet->setCellValue('F6', 'Stock No. : '.$card['item_code']);
        $sheet->setCellValue('A7', 'Description : '.$card['description']);
        $sheet->setCellValue('F7', 'Re-order Point : '.$card['reorder_level']);
        $sheet->setCellValue('A8', 'Unit of Measurement : '.$card['unit']);

        $sheet->mergeCells('A6:E6');
        $sheet->mergeCells('F6:G6');
        $sheet->mergeCells('A7:E7');
        $sheet->mergeCells('F7:G7');
        $sheet->mergeCells('A8:E8');
        $sheet->mergeCells('F8:G8');

        $infoBorder = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                ],
            ],
        ];
        $sheet->getStyle('A6:G8')->applyFromArray($infoBorder);

        $sheet->mergeCells('A9:A10');
        $sheet->mergeCells('B9:B10');
        $sheet->mergeCells('D9:E9');
        $sheet->mergeCells('G9:G10');

        $sheet->setCellValue('A9', 'Date');
        $sheet->setCellValue('B9', 'Reference');
        $sheet->setCellValue('C9', 'Receipt');
        $sheet->setCellValue('D9', 'Issue');
        $sheet->setCellValue('F9', 'Balance');
        $sheet->setCellValue('G9', 'No. of Days to Consume');
        $sheet->setCellValue('C10', 'Qty.');
        $sheet->setCellValue('D10', 'Qty.');
        $sheet->setCellValue('E10', 'Office');
        $sheet->setCellValue('F10', 'Qty.');

        $headerStyle = [
            'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 12],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A9:G10')->applyFromArray($headerStyle);
        $sheet->getStyle('C9')->getFont()->setItalic(true);
        $sheet->getStyle('D9')->getFont()->setItalic(true);
        $sheet->getStyle('F9')->getFont()->setItalic(true);
        $sheet->getStyle('C10')->getFont()->setBold(false)->setItalic(false);
        $sheet->getStyle('D10')->getFont()->setBold(false)->setItalic(false);
        $sheet->getStyle('E10')->getFont()->setBold(false)->setItalic(false);
        $sheet->getStyle('F10')->getFont()->setBold(false)->setItalic(false);

        $transactions = $card['transactions'] ?? [];
        $row = 11;
        $endRow = 11 + self::MIN_LEDGER_ROWS - 1;

        foreach ($transactions as $txn) {
            if ($row > $endRow + 50) {
                break;
            }

            $sheet->setCellValue('A'.$row, $txn['date'] ?? '');
            $sheet->setCellValue('B'.$row, $txn['reference'] ?? '');
            $sheet->setCellValue('C'.$row, $txn['receipt_qty'] ?? '');
            $sheet->setCellValue('D'.$row, $txn['issue_qty'] ?? '');
            $sheet->setCellValue('E'.$row, $txn['issue_office'] ?? '');
            $sheet->setCellValue('F'.$row, $txn['balance'] ?? '');
            $sheet->setCellValue('G'.$row, $card['days_to_consume'] ?? '');
            $row++;
        }

        $lastDataRow = max($endRow, $row - 1);
        $sheet->getStyle('A11:G'.$lastDataRow)->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'font' => [
                'name' => 'Times New Roman',
                'size' => 11,
            ],
        ]);

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(24);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(18);

        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
    }

    protected function addHeaderLogos(Worksheet $sheet): void
    {
        $bagongPath = public_path(config('owwa_mail.logos.bagong_pilipinas', 'images/bagong-pilipinas-form-logo.png'));
        $owwaPath = public_path(config('owwa_mail.logos.owwa', 'images/owwa-form-logo.png'));

        // Option A: height 2.33cm, keep natural aspect ratio (OWWA ≈ 2.33×2.33; Bagong ≈ 2.91×2.33).
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
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'Sheet';

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

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function xlsxBinary(array $cards): string
    {
        PhpExtensionGuard::ensureZipArchive();

        $spreadsheet = $this->makeSpreadsheet($cards);
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Unable to allocate a temporary stream for Fast Excel export.');
        }

        try {
            $meta = stream_get_meta_data($tmp);
            $path = $meta['uri'] ?? null;
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('Unable to resolve temporary Fast Excel path.');
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();

            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException('Unable to read Fast Excel binary.');
            }

            return $binary;
        } finally {
            fclose($tmp);
        }
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
