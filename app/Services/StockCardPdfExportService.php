<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockCardPdfExportService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    /**
     * PDF from the same filled OWWA spreadsheet used for Excel (LibreOffice headless).
     *
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     */
    public function downloadMerged(Collection $pairs, string $categorySlug): Response|StreamedResponse
    {
        return match ($categorySlug) {
            'semi_expendable' => $this->itemReport->downloadAnnexA1BulkPdf($pairs),
            'ppe' => $this->itemReport->downloadPropertyCardBulkPdf($pairs),
            default => $this->itemReport->downloadStockCardBulkPdf($pairs),
        };
    }
}
