<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Services\InventoryStockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class CoaReportController extends Controller
{
    public function __construct(
        protected InventoryStockService $stock
    ) {}

    /**
     * COA-compliant stock level report (PDF).
     */
    public function stockLevelReport(Request $request)
    {
        $offices = Office::orderBy('name')->get();
        $items = Item::with('category')->orderBy('name')->get();

        $rows = [];
        foreach ($items as $item) {
            foreach ($offices as $office) {
                $rows[] = [
                    'item_name' => $item->name,
                    'category' => $item->category?->name,
                    'office' => $office->name,
                    'stock' => $this->stock->getStock($item->id, $office->id),
                    'reorder_level' => $item->reorder_level,
                ];
            }
        }

        $pdf = Pdf::loadView('reports.coa-stock-level', [
            'title' => 'Stock Level Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'rows' => $rows,
        ]);

        return $pdf->download('coa-stock-level-' . now()->format('Y-m-d') . '.pdf');
    }

    /**
     * COA-compliant issuance report (PDF).
     */
    public function issuanceReport(Request $request)
    {
        $issuances = Issuance::with(['item', 'office', 'issuedTo'])
            ->orderByDesc('issuance_date')
            ->limit(500)
            ->get();

        $pdf = Pdf::loadView('reports.coa-issuance', [
            'title' => 'Issuance Report',
            'generated_at' => now()->format('F j, Y H:i'),
            'issuances' => $issuances,
        ]);

        return $pdf->download('coa-issuance-' . now()->format('Y-m-d') . '.pdf');
    }
}
