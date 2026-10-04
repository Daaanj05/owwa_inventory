<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\ProcurementSpreadsheetBuilder;
use App\Support\SupplyOfficeResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PurchaseRequestFastPdfExportService
{
    public function download(AcquisitionPaperwork $paperwork): Response
    {
        $pages = $this->buildPages($paperwork);

        $filename = OwwaExportFilename::transaction(
            'PR-fast',
            $paperwork->pr_number ?? (string) $paperwork->id,
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @param  Collection<int, AcquisitionPaperwork>|iterable<int, AcquisitionPaperwork>  $paperworks
     */
    public function downloadMany(iterable $paperworks): Response
    {
        $pages = [];

        foreach ($paperworks as $paperwork) {
            array_push($pages, ...$this->buildPages($paperwork));
        }

        abort_if($pages === [], 404, 'No purchase requests could be built for the selected date range.');

        return $this->makePdf($pages)->download(OwwaExportFilename::batch('PR-fast', ext: 'pdf'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing([
            'office',
            'requestingOffice',
            'department',
            'itemCategory',
            'lines.item.category',
        ]);

        $lines = $paperwork->lines instanceof Collection
            ? $paperwork->lines
            : $paperwork->lines()->with(['item.category'])->get();

        $maxRows = $this->maxRowsPerPage();
        $chunks = ProcurementSpreadsheetBuilder::chunkLines($lines->values(), $maxRows);
        $pageCount = count($chunks);
        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $officeSection = $this->officeSectionName($paperwork);
        $responsibilityCode = $this->responsibilityCenterCode($paperwork);
        $prBase = $this->formatControlNumber(
            $paperwork->pr_number,
            $paperwork->pr_date?->format('Y-m-d'),
        );
        $date = $paperwork->pr_date?->format('Y-m-d') ?? '';

        $pages = [];

        foreach ($chunks as $pageIndex => $chunkLines) {
            $isLastPage = $pageIndex === ($pageCount - 1);
            $suffix = $this->continuationSuffix($pageIndex, $pageCount);

            $pages[] = [
                'entity_name' => $entityName,
                'office_section' => $officeSection,
                'pr_no' => $prBase.$suffix,
                'date' => $date,
                'responsibility_center_code' => $responsibilityCode,
                'purpose' => $isLastPage ? (string) ($paperwork->purpose ?? '') : '',
                'requested_by_name' => $isLastPage ? (string) ($paperwork->requested_by_name ?? '') : '',
                'approved_by_name' => $isLastPage ? (string) ($paperwork->approved_by_name ?? '') : '',
                'requested_by_designation' => $isLastPage ? (string) ($paperwork->requested_by_designation ?? '') : '',
                'approved_by_designation' => $isLastPage ? (string) ($paperwork->approved_by_designation ?? '') : '',
                'include_footer' => $isLastPage,
                'lines' => $chunkLines->map(function (AcquisitionPaperworkLine $line): array {
                    return [
                        'stock_no' => $line->stockNumber(),
                        'unit' => (string) ($line->unit ?? $line->item?->unit ?? ''),
                        'description' => (string) ($line->description ?? $line->item?->name ?? ''),
                        'quantity' => (string) $line->quantity,
                        'unit_cost' => '',
                        'total_cost' => '',
                    ];
                })->values()->all(),
            ];
        }

        return $pages;
    }

    public function maxRowsPerPage(): int
    {
        return (int) (OwwaCellMapping::form('PR')['detail']['max_rows'] ?? 22);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.purchase-request-fast', [
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
        return Pdf::loadView('reports.purchase-request-fast', [
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

    protected function officeSectionName(AcquisitionPaperwork $paperwork): string
    {
        $paperwork->loadMissing(['office', 'requestingOffice', 'department']);

        $regional = app(SupplyOfficeResolver::class)->resolveOffice();

        return $regional?->name
            ?? $paperwork->requestingOffice?->name
            ?? $paperwork->department?->name
            ?? $paperwork->office?->name
            ?? '';
    }

    protected function responsibilityCenterCode(AcquisitionPaperwork $paperwork): string
    {
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

    protected function formatControlNumber(?string $candidate, ?string $date): string
    {
        $normalized = strtoupper(trim((string) ($candidate ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{4}$/', $normalized) === 1) {
            return $normalized;
        }

        $parsedDate = filled($date) ? Carbon::parse($date) : now();
        $year = $parsedDate->format('Y');

        preg_match_all('/\d+/', $normalized, $numericParts);
        $seed = implode('', $numericParts[0] ?? []);
        $serial = $seed !== '' ? ((int) substr($seed, -4)) : 0;
        if ($serial <= 0) {
            $serial = ((int) $parsedDate->format('dHis')) % 10000;
        }

        $serialPart = str_pad((string) $serial, 4, '0', STR_PAD_LEFT);

        return "{$year}-01-{$serialPart}";
    }
}
