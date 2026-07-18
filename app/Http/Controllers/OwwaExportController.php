<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Http\Concerns\LogsExportActivity;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\Distribution;
use App\Models\InspectionAcceptanceReport;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\PhysicalCountSession;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AcquisitionPaperworkPdfExportService;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaExportController extends Controller
{
    use LogsExportActivity;

    public function __construct(
        protected OwwaTemplateExportService $owwaExport,
        protected OwwaItemReportService $itemReport,
        protected AcquisitionPaperworkPdfExportService $acquisitionPdfExport,
    ) {}

    public function acquisition(Request $request, Acquisition $acquisition): StreamedResponse
    {
        $acquisition->load(['item.category', 'office', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $this->logExportActivity(
            'Exported OWWA acquisition report '.$acquisition->reference_code,
            $acquisition,
            ['form' => $formSlug],
        );

        return $this->owwaExport->downloadAcquisition($acquisition, null, $formSlug);
    }

    public function issuance(Request $request, Issuance $issuance): StreamedResponse
    {
        $issuance->load(['item.category', 'office', 'department', 'issuedBy', 'issuedTo']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $this->logExportActivity(
            'Exported OWWA issuance report '.$issuance->reference_code,
            $issuance,
            ['form' => $formSlug],
        );

        return $this->owwaExport->downloadIssuance($issuance, null, $formSlug);
    }

    public function transfer(Request $request, Transfer $transfer): StreamedResponse
    {
        $transfer->load(['item.category', 'fromOffice', 'toOffice', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $this->logExportActivity(
            'Exported OWWA transfer report '.$transfer->reference_code,
            $transfer,
            ['form' => $formSlug],
        );

        return $this->owwaExport->downloadTransfer($transfer, null, $formSlug);
    }

    public function disposal(Request $request, Disposal $disposal): StreamedResponse
    {
        $disposal->load(['item.category', 'office', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $this->logExportActivity(
            'Exported OWWA disposal report '.$disposal->reference_code,
            $disposal,
            ['form' => $formSlug],
        );

        return $this->owwaExport->downloadDisposal($disposal, null, $formSlug);
    }

    public function requisition(Requisition $requisition): StreamedResponse
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User && RequisitionExportActions::userCanExportRis($user),
            403,
        );
        abort_unless($requisition->canExportRis(), 403);

        $requisition->load(['office', 'department', 'requestedBy', 'approvedBy', 'items.item']);

        $this->logExportActivity(
            'Exported OWWA requisition report '.$requisition->reference_code,
            $requisition,
        );

        return $this->owwaExport->downloadRequisition($requisition);
    }

    public function item(Request $request, Item $item): StreamedResponse
    {
        $item->load('category');
        $formSlug = $request->query('form', '');
        $officeId = $request->query('office_id');

        $this->logExportActivity(
            'Exported OWWA item report '.$item->item_code,
            $item,
            ['form' => $formSlug, 'office_id' => $officeId],
        );

        return $this->itemReport->downloadItemReport(
            $item,
            (string) $formSlug,
            $officeId !== null && $officeId !== '' ? (int) $officeId : null,
            $request->query('unit_cost') !== null && $request->query('unit_cost') !== ''
                ? (float) $request->query('unit_cost')
                : null,
        );
    }

    public function physicalCount(PhysicalCountSession $physicalCountSession): StreamedResponse
    {
        $this->logExportActivity(
            'Exported physical count report '.$physicalCountSession->reference_code,
            $physicalCountSession,
        );

        return $this->itemReport->downloadPhysicalCount($physicalCountSession);
    }

    public function distribution(Distribution $distribution): StreamedResponse
    {
        $distribution->load(['item.category', 'office', 'department', 'distributedTo', 'distributedBy']);

        $this->logExportActivity(
            'Exported OWWA distribution report #'.$distribution->getKey(),
            $distribution,
        );

        return $this->owwaExport->downloadDistribution($distribution);
    }

    public function acquisitionPaperworkPr(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $acquisitionPaperwork->load(['office', 'department', 'itemCategory', 'lines.item']);

        $this->logExportActivity(
            'Exported acquisition paperwork PR '.$acquisitionPaperwork->pr_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkPr($acquisitionPaperwork);
    }

    public function acquisitionPaperworkPrPdf(AcquisitionPaperwork $acquisitionPaperwork): Response
    {
        $this->logExportActivity(
            'Exported acquisition paperwork PR PDF '.$acquisitionPaperwork->pr_number,
            $acquisitionPaperwork,
        );

        return $this->acquisitionPdfExport->downloadPrPdf($acquisitionPaperwork);
    }

    public function acquisitionPaperworkPo(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $acquisitionPaperwork->load(['office', 'department', 'itemCategory', 'lines.item', 'purchaseOrder.orderedLines.item']);

        if ($acquisitionPaperwork->purchaseOrder) {
            $this->logExportActivity(
                'Exported purchase order '.$acquisitionPaperwork->purchaseOrder->number,
                $acquisitionPaperwork->purchaseOrder,
            );

            return $this->acquisitionPdfExport->downloadPoExcel($acquisitionPaperwork->purchaseOrder);
        }

        $this->logExportActivity(
            'Exported acquisition paperwork PO '.$acquisitionPaperwork->po_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkPo($acquisitionPaperwork);
    }

    public function acquisitionPaperworkIar(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $acquisitionPaperwork->load([
            'office',
            'department',
            'itemCategory',
            'lines.item',
            'purchaseOrder.inspectionAcceptanceReport.lines.item',
        ]);

        if ($acquisitionPaperwork->purchaseOrder?->inspectionAcceptanceReport) {
            $iar = $acquisitionPaperwork->purchaseOrder->inspectionAcceptanceReport;
            $this->logExportActivity('Exported IAR '.$iar->number, $iar);

            return $this->acquisitionPdfExport->downloadIarExcel($iar);
        }

        $this->logExportActivity(
            'Exported acquisition paperwork IAR '.$acquisitionPaperwork->iar_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkIar($acquisitionPaperwork);
    }

    public function purchaseOrderExcel(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        $this->logExportActivity('Exported purchase order Excel '.$purchaseOrder->number, $purchaseOrder);

        return $this->acquisitionPdfExport->downloadPoExcel($purchaseOrder);
    }

    public function purchaseOrderPdf(PurchaseOrder $purchaseOrder): Response
    {
        $this->logExportActivity('Exported purchase order PDF '.$purchaseOrder->number, $purchaseOrder);

        return $this->acquisitionPdfExport->downloadPoPdf($purchaseOrder);
    }

    public function inspectionAcceptanceReportExcel(InspectionAcceptanceReport $inspectionAcceptanceReport): StreamedResponse
    {
        $this->logExportActivity(
            'Exported IAR Excel '.$inspectionAcceptanceReport->number,
            $inspectionAcceptanceReport,
        );

        return $this->acquisitionPdfExport->downloadIarExcel($inspectionAcceptanceReport);
    }

    public function inspectionAcceptanceReportPdf(InspectionAcceptanceReport $inspectionAcceptanceReport): Response
    {
        $this->logExportActivity(
            'Exported IAR PDF '.$inspectionAcceptanceReport->number,
            $inspectionAcceptanceReport,
        );

        return $this->acquisitionPdfExport->downloadIarPdf($inspectionAcceptanceReport);
    }

    /** @deprecated */
    public function procurementPr(AcquisitionPaperwork $procurementCase): StreamedResponse
    {
        return $this->acquisitionPaperworkPr($procurementCase);
    }

    /** @deprecated */
    public function procurementPo(AcquisitionPaperwork $procurementCase): StreamedResponse
    {
        return $this->acquisitionPaperworkPo($procurementCase);
    }

    /** @deprecated */
    public function procurementIar(AcquisitionPaperwork $procurementCase): StreamedResponse
    {
        return $this->acquisitionPaperworkIar($procurementCase);
    }
}
