<?php

namespace App\Http\Controllers;

use App\Http\Concerns\LogsExportActivity;
use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Transfer;
use App\Services\InventoryStockService;
use App\Support\OwwaExportFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class CoaReportController extends Controller
{
    use LogsExportActivity;

    public function __construct(
        protected InventoryStockService $stock
    ) {}

    /**
     * @return array<int>
     */
    private function coaReportIdsFromRequest(Request $request): array
    {
        $ids = $request->query('ids');

        if ($ids === null || $ids === '' || $ids === []) {
            return [];
        }

        if (is_string($ids)) {
            return array_values(array_filter(array_map('intval', explode(',', $ids))));
        }

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        return [];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Acquisition|Disposal|Issuance|Transfer>  $records
     */
    private function coaDownloadFilename(string $reportType, \Illuminate\Support\Collection $records): string
    {
        if ($records->isEmpty()) {
            return OwwaExportFilename::coaReport($reportType, now()->format('Y-m-d'));
        }

        if ($records->count() === 1) {
            $reference = (string) ($records->first()->reference_code ?? $records->first()->getKey());

            return OwwaExportFilename::coaReport($reportType, $reference);
        }

        return OwwaExportFilename::coaReport($reportType, now()->format('Y-m-d_His'), isBatch: true);
    }

    /**
     * COA-compliant stock level report (PDF).
     */
    public function stockLevelReport(Request $request)
    {
        $categoryId = (int) session('active_item_category_id', 0);

        $rows = $this->stock
            ->getStockLevelsList($categoryId > 0 ? $categoryId : null)
            ->map(fn (object $position): array => [
                'item_name' => $position->item_name,
                'category' => $position->category_name,
                'office' => $position->office_name,
                'stock' => $position->stock,
                'reorder_level' => $position->reorder_level,
            ])
            ->all();

        $this->logExportActivity('Exported COA stock level report');

        $pdf = Pdf::loadView('reports.coa-stock-level', [
            'title' => 'Stock Level Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'rows' => $rows,
        ]);

        return $pdf->download(OwwaExportFilename::coaReport('StockLevel', now()->format('Y-m-d')));
    }

    /**
     * COA-compliant issuance report (PDF).
     */
    public function issuanceReport(Request $request)
    {
        $categoryId = (int) session('active_item_category_id', 0);
        $ids = $this->coaReportIdsFromRequest($request);
        $issuances = Issuance::with(['item', 'office', 'issuedTo'])
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($categoryId > 0, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('item_category_id', $categoryId)))
            ->orderByDesc('issuance_date')
            ->limit(500)
            ->get();

        $this->logExportActivity('Exported COA issuance report', properties: ['ids' => $ids]);

        $pdf = Pdf::loadView('reports.coa-issuance', [
            'title' => 'Issuance Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'issuances' => $issuances,
        ]);

        return $pdf->download($this->coaDownloadFilename('Issuance', $issuances));
    }

    public function acquisitionReport(Request $request)
    {
        $categoryId = (int) session('active_item_category_id', 0);
        $ids = $this->coaReportIdsFromRequest($request);

        $acquisitions = Acquisition::with(['item', 'office', 'recordedBy'])
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($categoryId > 0, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('item_category_id', $categoryId)))
            ->orderByDesc('acquisition_date')
            ->limit(500)
            ->get();

        $this->logExportActivity('Exported COA acquisition report', properties: ['ids' => $ids]);

        $pdf = Pdf::loadView('reports.coa-acquisition', [
            'title' => 'Acquisition Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'acquisitions' => $acquisitions,
        ]);

        return $pdf->download($this->coaDownloadFilename('Acquisition', $acquisitions));
    }

    public function transferReport(Request $request)
    {
        $categoryId = (int) session('active_item_category_id', 0);
        $ids = $this->coaReportIdsFromRequest($request);

        $transfers = Transfer::with(['item', 'fromOffice', 'toOffice', 'recordedBy'])
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($categoryId > 0, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('item_category_id', $categoryId)))
            ->orderByDesc('transfer_date')
            ->limit(500)
            ->get();

        $this->logExportActivity('Exported COA transfer report', properties: ['ids' => $ids]);

        $pdf = Pdf::loadView('reports.coa-transfer', [
            'title' => 'Transfer Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'transfers' => $transfers,
        ]);

        return $pdf->download($this->coaDownloadFilename('Transfer', $transfers));
    }

    public function disposalReport(Request $request)
    {
        $categoryId = (int) session('active_item_category_id', 0);
        $ids = $this->coaReportIdsFromRequest($request);

        $disposals = Disposal::with(['item', 'office', 'recordedBy'])
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($categoryId > 0, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('item_category_id', $categoryId)))
            ->orderByDesc('disposal_date')
            ->limit(500)
            ->get();

        $this->logExportActivity('Exported COA disposal report', properties: ['ids' => $ids]);

        $pdf = Pdf::loadView('reports.coa-disposal', [
            'title' => 'Disposal Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'disposals' => $disposals,
        ]);

        return $pdf->download($this->coaDownloadFilename('Disposal', $disposals));
    }
}
