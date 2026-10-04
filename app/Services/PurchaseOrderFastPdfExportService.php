<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\PesoAmountInWords;
use App\Support\ProcurementSpreadsheetBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class PurchaseOrderFastPdfExportService
{
    public function download(PurchaseOrder $purchaseOrder): Response
    {
        $pages = $this->buildPages($purchaseOrder);

        $filename = OwwaExportFilename::transaction(
            'PO-fast',
            $purchaseOrder->number ?? (string) $purchaseOrder->id,
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @param  Collection<int, PurchaseOrder>|iterable<int, PurchaseOrder>  $purchaseOrders
     */
    public function downloadMany(iterable $purchaseOrders): Response
    {
        $pages = [];

        foreach ($purchaseOrders as $purchaseOrder) {
            array_push($pages, ...$this->buildPages($purchaseOrder));
        }

        abort_if($pages === [], 404, 'No purchase orders could be built for the selected date range.');

        return $this->makePdf($pages)->download(OwwaExportFilename::batch('PO-fast', ext: 'pdf'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing([
            'supplier',
            'purchaseRequest.office',
            'purchaseRequest.requestingOffice',
            'purchaseRequest.department',
            'purchaseRequest.itemCategory',
            'orderedLines.item.category',
        ]);

        $lines = $purchaseOrder->orderedLines instanceof Collection
            ? $purchaseOrder->orderedLines
            : $purchaseOrder->orderedLines()->with(['item.category'])->get();

        $maxRows = $this->maxRowsPerPage();
        $chunks = ProcurementSpreadsheetBuilder::chunkLines($lines->values(), $maxRows);
        $pageCount = count($chunks);
        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $poBase = (string) ($purchaseOrder->number ?? '');
        $date = $purchaseOrder->po_date?->format('Y-m-d') ?? '';
        $totalAmount = (float) $lines->sum(fn (PurchaseOrderLine $line): float => (float) ($line->amount ?? 0));
        $supplier = trim((string) ($purchaseOrder->supplier_name ?: $purchaseOrder->supplier?->name ?: ''));
        $address = trim((string) ($purchaseOrder->supplier_address ?? ''));
        $tin = trim((string) ($purchaseOrder->supplier_tin ?: $purchaseOrder->supplier?->tin ?: ''));

        $pages = [];

        foreach ($chunks as $pageIndex => $chunkLines) {
            $isLastPage = $pageIndex === ($pageCount - 1);
            $suffix = $this->continuationSuffix($pageIndex, $pageCount);

            $pages[] = [
                'entity_name' => $entityName,
                'supplier' => $supplier,
                'address' => $address,
                'tin' => $tin,
                'po_no' => $poBase.$suffix,
                'date' => $date,
                'mode_of_procurement' => (string) ($purchaseOrder->mode_of_procurement ?? ''),
                'place_of_delivery' => (string) ($purchaseOrder->place_of_delivery ?? ''),
                'date_of_delivery' => $purchaseOrder->date_of_delivery?->format('Y-m-d') ?? '',
                'delivery_term' => (string) ($purchaseOrder->delivery_term ?? ''),
                'payment_term' => (string) ($purchaseOrder->payment_term ?? ''),
                'total_amount' => $isLastPage ? $totalAmount : null,
                'total_amount_formatted' => $isLastPage ? number_format($totalAmount, 2) : '',
                'total_amount_in_words' => $isLastPage ? PesoAmountInWords::format($totalAmount) : '',
                'include_footer' => $isLastPage,
                'lines' => $chunkLines->map(function (PurchaseOrderLine $line): array {
                    return [
                        'stock_no' => $line->stockNumber(),
                        'unit' => (string) ($line->unit ?? $line->item?->unit ?? ''),
                        'description' => (string) ($line->description ?? $line->item?->name ?? ''),
                        'quantity' => (string) $line->po_quantity,
                        'unit_cost' => $line->unit_cost !== null ? number_format((float) $line->unit_cost, 2) : '',
                        'amount' => $line->amount !== null ? number_format((float) $line->amount, 2) : '',
                    ];
                })->values()->all(),
            ];
        }

        return $pages;
    }

    public function maxRowsPerPage(): int
    {
        return (int) (OwwaCellMapping::form('PO')['detail']['max_rows'] ?? 15);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.purchase-order-fast', [
            'pages' => $pages,
            'minRows' => $this->maxRowsPerPage(),
            'forDomPdf' => $forDomPdf,
            'showGeneratedOn' => $showGeneratedOn,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])->render();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    protected function makePdf(array $pages): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.purchase-order-fast', [
            'pages' => $pages,
            'minRows' => $this->maxRowsPerPage(),
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            // Full font embed: subsetting can drop glyphs so some PDF viewers show blank supplier text.
            ->setOption('isFontSubsettingEnabled', false);
    }

    protected function continuationSuffix(int $pageIndex, int $pageCount): string
    {
        if ($pageCount <= 1 || $pageIndex === 0) {
            return '';
        }

        return ' (Cont. '.($pageIndex + 1).')';
    }
}
