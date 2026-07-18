<?php

namespace App\Services;

use App\Support\OwwaExportFilename;
use App\Support\PdfSafeText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnexA4PdfExportService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    public function download(Collection $pairs): Response|StreamedResponse
    {
        $tabs = $this->itemReport->buildAnnexA4ExportTabs($pairs);

        abort_if($tabs === [], 404);

        $cards = array_map(function (array $tab): array {
            return [
                'appendix' => 'Annex A.4',
                'title' => 'REGISTRY OF SEMI-EXPENDABLE PROPERTY ISSUED',
                'header' => PdfSafeText::normalizeArray($tab['header'] ?? []),
                'sheetName' => $tab['sheetName'] ?? '',
                'entries' => array_map(
                    fn (array $entry): array => PdfSafeText::normalizeArray($entry),
                    $tab['entries'] ?? [],
                ),
            ];
        }, $tabs);

        $pdf = Pdf::loadView('owwa.annex-a4-pdf', [
            'cards' => $cards,
            'generatedAt' => now(),
        ])->setPaper('legal', 'landscape');

        return $pdf->download(OwwaExportFilename::batch('AnnexA4', ext: 'pdf'));
    }
}
