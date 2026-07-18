<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\PurchaseOrder;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\OwwaSpreadsheetLayoutHelper;
use App\Support\OwwaTemplateLoader;
use App\Support\PdfSafeText;
use App\Support\PesoAmountInWords;
use App\Support\PhpExtensionGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcquisitionPaperworkPdfExportService
{
    public function __construct(
        protected OwwaTemplateExportService $excelExport,
    ) {}

    public function downloadPrPdf(AcquisitionPaperwork $paperwork): Response
    {
        $paperwork->loadMissing(['office', 'requestingOffice', 'department', 'itemCategory', 'lines.item.category', 'lines.item.uacsObjectCode']);

        $pdf = Pdf::loadView('owwa.acquisition-pr-pdf', [
            'paperwork' => $paperwork,
            'officeName' => app(\App\Support\SupplyOfficeResolver::class)->resolveOfficeName() ?? $paperwork->office?->name,
            'lines' => $paperwork->lines,
            'generatedAt' => now(),
        ])->setPaper('legal', 'portrait');

        return $pdf->download(OwwaExportFilename::transaction('PR', $paperwork->pr_number ?? (string) $paperwork->id, 'pdf'));
    }

    public function downloadPoPdf(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->loadMissing([
            'purchaseRequest.office',
            'purchaseRequest.itemCategory',
            'orderedLines.item.category',
            'orderedLines.item.uacsObjectCode',
        ]);

        $lines = $purchaseOrder->orderedLines;
        $total = $purchaseOrder->totalAmount();

        $pdf = Pdf::loadView('owwa.acquisition-po-pdf', [
            'purchaseOrder' => $purchaseOrder,
            'paperwork' => $purchaseOrder->purchaseRequest,
            'lines' => $lines,
            'totalAmount' => $total,
            'totalAmountInWords' => PesoAmountInWords::format($total),
            'technicalSpecifications' => PdfSafeText::normalize((string) ($purchaseOrder->technical_specifications ?? 'N/A')),
            'generatedAt' => now(),
        ])->setPaper('legal', 'portrait');

        return $pdf->download(OwwaExportFilename::transaction('PO', $purchaseOrder->number ?? (string) $purchaseOrder->id, 'pdf'));
    }

    public function downloadIarPdf(InspectionAcceptanceReport $iar): Response
    {
        $iar->loadMissing([
            'purchaseOrder.purchaseRequest.office',
            'purchaseOrder.purchaseRequest.itemCategory',
            'lines.item.category',
            'lines.item.uacsObjectCode',
        ]);

        $pdf = Pdf::loadView('owwa.acquisition-iar-pdf', [
            'iar' => $iar,
            'purchaseOrder' => $iar->purchaseOrder,
            'paperwork' => $iar->purchaseOrder?->purchaseRequest,
            'lines' => $iar->lines,
            'generatedAt' => now(),
        ])->setPaper('legal', 'portrait');

        return $pdf->download(OwwaExportFilename::transaction('IAR', $iar->number ?? (string) $iar->id, 'pdf'));
    }

    public function downloadPoExcel(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        PhpExtensionGuard::ensureZipArchive();
        $purchaseOrder->loadMissing([
            'purchaseRequest.office',
            'purchaseRequest.itemCategory',
            'orderedLines.item.category',
            'orderedLines.item.uacsObjectCode',
        ]);

        $paperwork = $purchaseOrder->purchaseRequest;
        abort_if($paperwork === null, 404);

        $templateFilename = $this->excelExport->getTemplatePathForCategory(
            'acquisition_paperwork',
            $paperwork->itemCategory,
            'po',
        );
        $absolutePath = $this->excelExport->requireTemplateAbsolutePath($templateFilename);
        $spreadsheet = OwwaTemplateLoader::load($absolutePath);
        $sheet = $spreadsheet->getSheet(0);

        $detail = (array) OwwaCellMapping::form('PO')['detail'];
        $startRow = (int) ($detail['start_row'] ?? 16);
        $maxRows = (int) ($detail['max_rows'] ?? 16);
        $footerStartRow = (int) ($detail['footer_start_row'] ?? 32);
        $styleRow = (int) ($detail['style_row'] ?? $startRow);
        $highestColumn = (string) ($detail['highest_column'] ?? 'F');
        $columns = (array) ($detail['columns'] ?? []);

        $lines = $purchaseOrder->orderedLines->values();
        $lineCount = $lines->count();
        // Keep one empty detail row after the last item before the totals/footer.
        $neededDetailRows = max($lineCount + 1, 1);
        $extraRows = max(0, $neededDetailRows - $maxRows);

        if ($extraRows > 0) {
            OwwaSpreadsheetLayoutHelper::insertStyledLedgerRows(
                $sheet,
                $footerStartRow,
                $extraRows,
                $styleRow,
                $highestColumn,
            );
        }

        $totalRow = $footerStartRow + $extraRows;
        $cellValues = $this->poCellValues($purchaseOrder, $lines, $startRow, $columns, $totalRow);

        $this->excelExport->applyFilledProcurementSheet($sheet, $templateFilename, $cellValues);
        $this->clearPoAccountingCells($sheet, $totalRow);

        $binary = $this->excelExport->spreadsheetToXlsxBinary($spreadsheet);
        $spreadsheet->disconnectWorksheets();
        $filename = $this->excelExport->buildOwwaExportFilename('PO', $purchaseOrder->number ?? (string) $purchaseOrder->id);

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function downloadIarExcel(InspectionAcceptanceReport $iar): StreamedResponse
    {
        $iar->loadMissing(['purchaseOrder.purchaseRequest']);
        $paperwork = $iar->purchaseOrder?->purchaseRequest;
        abort_if($paperwork === null, 404);

        // Reuse paperwork IAR export path with IAR quantities mirrored onto a temporary view.
        $paperwork->setRelation('lines', $iar->lines->map(function ($line) {
            $proxy = $line->purchaseRequestLine?->replicate() ?? new \App\Models\AcquisitionPaperworkLine;
            $proxy->id = $line->acquisition_paperwork_line_id;
            $proxy->item_id = $line->item_id;
            $proxy->description = $line->description;
            $proxy->unit = $line->unit;
            $proxy->quantity = $line->iar_quantity;
            $proxy->unit_cost = $line->unit_cost;
            $proxy->amount = $line->amount;
            $proxy->setRelation('item', $line->item);

            return $proxy;
        }));

        $paperwork->iar_number = $iar->number;
        $paperwork->iar_date = $iar->iar_date;
        $paperwork->supplier = $iar->purchaseOrder?->supplier_name;
        $paperwork->inspection_officer_name = $iar->inspection_officer_name;
        $paperwork->custodian_name = $iar->custodian_name;
        $paperwork->iar_data = [
            'invoice_no' => $iar->invoice_number,
            'invoice_date' => $iar->invoice_date?->toDateString(),
            'date_inspected' => $iar->date_inspected?->toDateString(),
            'date_received' => $iar->date_received?->toDateString(),
        ];
        $paperwork->po_number = $iar->purchaseOrder?->number;
        $paperwork->po_date = $iar->purchaseOrder?->po_date;

        return $this->excelExport->downloadAcquisitionPaperworkIar($paperwork);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\PurchaseOrderLine>  $lines
     * @param  array<string, string>  $columns
     * @return array<string, string|int|float|null>
     */
    protected function poCellValues(
        PurchaseOrder $purchaseOrder,
        $lines,
        int $startRow,
        array $columns,
        int $totalRow,
    ): array {
        $values = [];

        OwwaCellMapping::applyHeader($values, (array) (OwwaCellMapping::form('PO')['header'] ?? []), [
            'supplier' => $purchaseOrder->supplier_name ?? '',
            'po_no' => $purchaseOrder->number ?? '',
            'date' => $purchaseOrder->po_date?->format('Y-m-d') ?? '',
            'address' => $purchaseOrder->supplier_address ?? '',
            'tin' => $purchaseOrder->supplier_tin ?? '',
            'mode_of_procurement' => $purchaseOrder->mode_of_procurement ?? '',
            'place_of_delivery' => $purchaseOrder->place_of_delivery ?? '',
            'delivery_term' => $purchaseOrder->delivery_term ?? '',
            'date_of_delivery' => $purchaseOrder->date_of_delivery?->format('Y-m-d') ?? '',
            'payment_term' => $purchaseOrder->payment_term ?? '',
            'fund_cluster' => '',
        ]);

        foreach ($lines->values() as $index => $line) {
            $row = $startRow + $index;
            $values[OwwaCellMapping::columnCell($columns['stock_no'] ?? 'A', $row)] = $line->stockNumber();
            $values[OwwaCellMapping::columnCell($columns['unit'] ?? 'B', $row)] = $line->unit ?? $line->item?->unit ?? '';
            $values[OwwaCellMapping::columnCell($columns['description'] ?? 'C', $row)] = $line->description ?? $line->item?->name ?? '';
            $values[OwwaCellMapping::columnCell($columns['quantity'] ?? 'D', $row)] = (string) $line->po_quantity;
            $values[OwwaCellMapping::columnCell($columns['unit_cost'] ?? 'E', $row)] = $line->unit_cost !== null ? (float) $line->unit_cost : '';
            $values[OwwaCellMapping::columnCell($columns['amount'] ?? 'F', $row)] = $line->amount !== null ? (float) $line->amount : '';
        }

        $totalAmount = (float) $lines->sum('amount');
        $values['A'.$totalRow] = PesoAmountInWords::format($totalAmount);
        $values['F'.$totalRow] = $totalAmount;

        return $values;
    }

    protected function clearPoAccountingCells(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $totalRow): void
    {
        foreach (['A45', 'A46', 'D45', 'D46'] as $cell) {
            // Shift accounting rows when detail rows were inserted before the footer.
            $row = (int) preg_replace('/\D+/', '', $cell);
            $column = preg_replace('/\d+/', '', $cell);
            $shifted = $column.($row + max(0, $totalRow - 32));
            $sheet->setCellValue($shifted, '');
            $sheet->setCellValue($cell, '');
        }
    }
}
