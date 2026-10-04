<?php

namespace App\Services;

use App\Models\InspectionAcceptanceReport;
use App\Models\InspectionAcceptanceReportLine;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\ProcurementSpreadsheetBuilder;
use App\Support\SupplyOfficeResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class InspectionAcceptanceReportFastPdfExportService
{
    public function download(InspectionAcceptanceReport $iar): Response
    {
        $pages = $this->buildPages($iar);

        $filename = OwwaExportFilename::transaction(
            'IAR-fast',
            $iar->number ?? (string) $iar->id,
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @param  Collection<int, InspectionAcceptanceReport>|iterable<int, InspectionAcceptanceReport>  $iars
     */
    public function downloadMany(iterable $iars): Response
    {
        $pages = [];

        foreach ($iars as $iar) {
            array_push($pages, ...$this->buildPages($iar));
        }

        abort_if($pages === [], 404, 'No inspection acceptance reports could be built for the selected date range.');

        return $this->makePdf($pages)->download(OwwaExportFilename::batch('IAR-fast', ext: 'pdf'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(InspectionAcceptanceReport $iar): array
    {
        $iar->loadMissing([
            'purchaseOrder.purchaseRequest.office',
            'purchaseOrder.purchaseRequest.requestingOffice',
            'purchaseOrder.purchaseRequest.department',
            'purchaseOrder.purchaseRequest.itemCategory',
            'lines.item.category',
        ]);

        $lines = $iar->lines instanceof Collection
            ? $iar->lines->filter(fn (InspectionAcceptanceReportLine $line): bool => (int) $line->iar_quantity > 0)->values()
            : $iar->lines()->where('iar_quantity', '>', 0)->with(['item.category'])->get();

        $maxRows = $this->maxRowsPerPage();
        $chunks = ProcurementSpreadsheetBuilder::chunkLines($lines->values(), $maxRows);
        $pageCount = count($chunks);
        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $paperwork = $iar->purchaseOrder?->purchaseRequest;
        $po = $iar->purchaseOrder;
        $iarBase = (string) ($iar->number ?? '');
        $poNoDate = trim(
            (string) ($po?->number ?? '').
            ($po?->po_date ? ' / '.$po->po_date->format('Y-m-d') : ''),
        );
        $requisitioningOffice = $this->requisitioningOfficeName($paperwork);
        $responsibilityCode = $this->responsibilityCenterCode($paperwork);

        $pages = [];

        foreach ($chunks as $pageIndex => $chunkLines) {
            $isLastPage = $pageIndex === ($pageCount - 1);
            $suffix = $this->continuationSuffix($pageIndex, $pageCount);

            $pages[] = [
                'entity_name' => $entityName,
                'supplier' => (string) ($po?->supplier_name ?? ''),
                'po_no_date' => $poNoDate,
                'requisitioning_office' => $requisitioningOffice,
                'responsibility_center_code' => $responsibilityCode,
                'iar_no' => $iarBase.$suffix,
                'date' => $iar->iar_date?->format('Y-m-d') ?? '',
                'invoice_no' => (string) ($iar->invoice_number ?? ''),
                'invoice_date' => $iar->invoice_date?->format('Y-m-d') ?? '',
                'date_inspected' => $isLastPage ? ($iar->date_inspected?->format('Y-m-d') ?? '') : '',
                'date_received' => $isLastPage ? ($iar->date_received?->format('Y-m-d') ?? '') : '',
                'inspection_officer_name' => $isLastPage ? (string) ($iar->inspection_officer_name ?? '') : '',
                'custodian_name' => $isLastPage ? (string) ($iar->custodian_name ?? '') : '',
                'include_footer' => $isLastPage,
                'lines' => $chunkLines->map(function (InspectionAcceptanceReportLine $line): array {
                    return [
                        'stock_no' => $line->stockNumber(),
                        'description' => (string) ($line->description ?? $line->item?->name ?? ''),
                        'unit' => (string) ($line->unit ?? $line->item?->unit ?? ''),
                        'quantity' => (string) $line->iar_quantity,
                    ];
                })->values()->all(),
            ];
        }

        return $pages;
    }

    public function maxRowsPerPage(): int
    {
        return (int) (OwwaCellMapping::form('IAR')['detail']['max_rows'] ?? 13);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.inspection-acceptance-report-fast', [
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
        return Pdf::loadView('reports.inspection-acceptance-report-fast', [
            'pages' => $pages,
            'minRows' => $this->maxRowsPerPage(),
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
    }

    protected function requisitioningOfficeName(?\App\Models\AcquisitionPaperwork $paperwork): string
    {
        if ($paperwork === null) {
            return '';
        }

        $paperwork->loadMissing(['office', 'requestingOffice', 'department']);

        return $paperwork->requestingOffice?->name
            ?? $paperwork->department?->name
            ?? $paperwork->office?->name
            ?? '';
    }

    protected function responsibilityCenterCode(?\App\Models\AcquisitionPaperwork $paperwork): string
    {
        if ($paperwork === null) {
            return '';
        }

        $paperwork->loadMissing(['office', 'requestingOffice', 'department']);

        $regional = app(SupplyOfficeResolver::class)->resolveOffice();

        return $regional?->code
            ?? $paperwork->requestingOffice?->code
            ?? $paperwork->department?->code
            ?? $paperwork->office?->code
            ?? '';
    }

    protected function continuationSuffix(int $pageIndex, int $pageCount): string
    {
        if ($pageCount <= 1 || $pageIndex === 0) {
            return '';
        }

        return ' (Cont. '.($pageIndex + 1).')';
    }
}
