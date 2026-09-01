<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnexA4PdfExportService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    /**
     * PDF from the same filled Annex A.4 spreadsheet used for Excel (LibreOffice headless).
     *
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    public function download(Collection $pairs): Response|StreamedResponse
    {
        return $this->itemReport->downloadAnnexA4BulkPdf($pairs);
    }
}
