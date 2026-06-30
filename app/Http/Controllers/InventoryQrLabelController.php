<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Issuance;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\InventoryQrLabelService;
use App\Support\OwwaExportFilename;
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

    public function acquisition(Acquisition $acquisition, InventoryQrLabelService $labels): SymfonyResponse
    {
        $this->authorizeSupplyCustodian();

        $acquisition->load(['item.category', 'office', 'inventoryUnits']);

        $slug = $acquisition->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            abort(404, 'QR labels are only available for PPE and semi-expendable acquisitions.');
        }

        $labelRows = $labels->labelsForAcquisition($acquisition);

        if ($labelRows->isEmpty()) {
            app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
            $acquisition->load(['item.category', 'office', 'inventoryUnits']);
            $labelRows = $labels->labelsForAcquisition($acquisition);
        }

        if ($labelRows->isEmpty()) {
            abort(404, 'No inventory units on this acquisition. Check that quantity is greater than zero.');
        }

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Unit QR labels — '.$acquisition->reference_code,
            'labels' => $labelRows,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('Acquisition', (string) $acquisition->reference_code));
    }

    public function issuance(Issuance $issuance, InventoryQrLabelService $labels): SymfonyResponse
    {
        $this->authorizeSupplyCustodian();

        $issuance->load(['item.category', 'office']);

        if (blank($issuance->property_number)) {
            abort(404, 'This issuance has no property number for QR labeling.');
        }

        $slug = $issuance->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            abort(404, 'QR labels are only available for PPE and semi-expendable issuances.');
        }

        $labelRows = $labels->labelsForIssuance($issuance);

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Property QR label',
            'labels' => $labelRows,
        ])->setPaper([0, 0, 288, 432], 'portrait');

        $filename = OwwaExportFilename::qrLabel('Issuance', (string) $issuance->property_number);

        return $pdf->download($filename);
    }

    public function physicalCountSession(PhysicalCountSession $physicalCountSession, InventoryQrLabelService $labels): SymfonyResponse
    {
        $this->authorizeSupplyCustodian();

        if (! $physicalCountSession->supportsQrScanning()) {
            abort(404, 'QR labels are only available for PPE and semi-expendable count sessions.');
        }

        $labelRows = $labels->labelsForSession($physicalCountSession);

        if ($labelRows->isEmpty()) {
            abort(404, 'No property numbers on this count session. Load expected assets first.');
        }

        $pdf = Pdf::loadView('reports.qr-labels', [
            'title' => 'Physical count QR labels — '.$physicalCountSession->reference_code,
            'labels' => $labelRows,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(OwwaExportFilename::qrLabel('PhysicalCount', (string) $physicalCountSession->reference_code));
    }

    protected function authorizeSupplyCustodian(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isSupplyCustodian()) {
            abort(403);
        }
    }
}
