<?php

namespace App\Http\Controllers;

use App\Models\AcquisitionPaperwork;
use App\Models\InventoryUnit;
use App\Models\StockOpeningBalanceBatch;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use App\Services\UserActivityLogger;
use App\Support\OwwaExportFilename;
use App\Support\ScanAssetHandoff;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InventoryQrLabelController extends Controller
{
    public function acquisitionPaperwork(AcquisitionPaperwork $acquisitionPaperwork, InventoryQrLabelService $labels): SymfonyResponse
    {
        $this->authorizeSupplyCustodian();

        if (! $acquisitionPaperwork->isReceived()) {
            abort(404, 'QR labels are available after custodian receipt is recorded.');
        }

        if (! $labels->supportsPaperworkQrLabels($acquisitionPaperwork)) {
            abort(404, 'QR labels are only available for PPE and semi-expendable acquisitions.');
        }

        $labelRows = $labels->labelsForPaperwork($acquisitionPaperwork);

        if ($labelRows->isEmpty()) {
            abort(404, 'No inventory units on this acquisition case. Check that line quantities are greater than zero.');
        }

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Unit QR labels — '.$acquisitionPaperwork->reference_code,
            'labels' => $labelRows,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('AcquisitionPaperwork', (string) $acquisitionPaperwork->reference_code));
    }

    public function unit(InventoryUnit $inventoryUnit, InventoryQrLabelService $labels): SymfonyResponse
    {
        $user = $this->authorizeSupplyCustodian();

        if (ScanAssetHandoff::resolveActionableUnit($inventoryUnit, $user) === null) {
            abort(404, 'Only PPE and semi-expendable units in your office that are not disposed can be reprinted.');
        }

        app(UserActivityLogger::class)->record(
            $user,
            'reprinted_qr_label',
            'Reprinted QR label for '.$inventoryUnit->property_number,
            $inventoryUnit,
            ['property_number' => $inventoryUnit->property_number],
        );

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Unit QR label — '.$inventoryUnit->property_number,
            'labels' => $labels->labelsForUnit($inventoryUnit),
        ])->setPaper('a4', 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('Unit', (string) $inventoryUnit->property_number));
    }

    public function openingBalance(StockOpeningBalanceBatch $stockOpeningBalanceBatch, InventoryQrLabelService $labels): SymfonyResponse
    {
        $this->authorizeSupplyCustodian();

        if (! $stockOpeningBalanceBatch->isConfirmed()) {
            abort(404, 'QR labels are available after the opening balance is confirmed.');
        }

        if (! $labels->supportsOpeningBalanceQrLabels($stockOpeningBalanceBatch)) {
            abort(404, 'QR labels are only available for PPE and semi-expendable opening stock.');
        }

        $labelRows = $labels->labelsForOpeningBalanceBatch($stockOpeningBalanceBatch);

        if ($labelRows->isEmpty()) {
            abort(404, 'No inventory units on this opening balance.');
        }

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Unit QR labels — '.$stockOpeningBalanceBatch->reference_code,
            'labels' => $labelRows,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('OpeningBalance', (string) $stockOpeningBalanceBatch->reference_code));
    }

    protected function authorizeSupplyCustodian(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isSupplyCustodian()) {
            abort(403);
        }

        return $user;
    }
}
