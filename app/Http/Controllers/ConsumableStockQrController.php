<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use App\Services\InventoryStockService;
use App\Support\ConsumableStockQrPayload;
use App\Support\OwwaExportFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ConsumableStockQrController extends Controller
{
    public function show(Item $item, Office $office, Request $request, InventoryStockService $stock): View
    {
        $item->loadMissing('category');

        abort_unless($item->category?->getTemplateSlug() === 'consumables', 404);

        $balance = $stock->getStock((int) $item->id, (int) $office->id);

        return view('inventory.stock-lookup', [
            'item' => $item,
            'office' => $office,
            'balance' => $balance,
            'unitCostKey' => $request->query('uck'),
            'payload' => ConsumableStockQrPayload::encode($item, $office, $request->query('uck')),
        ]);
    }

    public function labels(Item $item, Office $office, InventoryQrLabelService $labels): SymfonyResponse
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isSupplyCustodian()) {
            abort(403);
        }

        $item->loadMissing('category');
        abort_unless($item->category?->getTemplateSlug() === 'consumables', 404);

        $labelRows = $labels->labelsForConsumableStock($item, $office);

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Stock QR label — '.$item->item_code,
            'labels' => $labelRows,
        ])->setPaper([0, 0, 288, 432], 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('Stock', (string) $item->item_code));
    }
}
