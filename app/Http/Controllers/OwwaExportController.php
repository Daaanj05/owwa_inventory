<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Http\Concerns\AuthorizesOwwaExports;
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
use App\Services\EmployeeDistributionExportService;
use App\Services\EmployeeDistributionInventoryService;
use App\Services\InspectionAcceptanceReportFastExcelExportService;
use App\Services\InspectionAcceptanceReportFastPdfExportService;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Services\PurchaseOrderFastExcelExportService;
use App\Services\PurchaseOrderFastPdfExportService;
use App\Services\PurchaseRequestFastExcelExportService;
use App\Services\PurchaseRequestFastPdfExportService;
use App\Services\RpciFastPdfExportService;
use App\Services\RpcppeFastPdfExportService;
use App\Services\RpcspFastPdfExportService;
use App\Services\RsmiFastPdfExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaExportController extends Controller
{
    use AuthorizesOwwaExports;
    use LogsExportActivity;

    public function __construct(
        protected OwwaTemplateExportService $owwaExport,
        protected OwwaItemReportService $itemReport,
        protected PurchaseRequestFastPdfExportService $purchaseRequestFastPdfExport,
        protected PurchaseRequestFastExcelExportService $purchaseRequestFastExcelExport,
        protected PurchaseOrderFastPdfExportService $purchaseOrderFastPdfExport,
        protected PurchaseOrderFastExcelExportService $purchaseOrderFastExcelExport,
        protected InspectionAcceptanceReportFastPdfExportService $inspectionAcceptanceReportFastPdfExport,
        protected InspectionAcceptanceReportFastExcelExportService $inspectionAcceptanceReportFastExcelExport,
        protected RsmiFastPdfExportService $rsmiFastPdfExport,
        protected RpciFastPdfExportService $rpciFastPdfExport,
        protected RpcppeFastPdfExportService $rpcppeFastPdfExport,
        protected RpcspFastPdfExportService $rpcspFastPdfExport,
        protected EmployeeDistributionExportService $employeeDistributionExport,
    ) {}

    public function acquisition(Request $request, Acquisition $acquisition): StreamedResponse|Response
    {
        $this->authorizeAcquisitionExport($acquisition);
        $acquisition->load(['item.category', 'office', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported OWWA acquisition PDF ' : 'Exported OWWA acquisition report ').$acquisition->reference_code,
            $acquisition,
            ['form' => $formSlug, 'format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return $this->owwaExport->downloadAcquisitionPdf($acquisition, null, $formSlug);
        }

        return $this->owwaExport->downloadAcquisition($acquisition, null, $formSlug);
    }

    public function issuance(Request $request, Issuance $issuance): StreamedResponse|Response
    {
        $this->authorizeIssuanceExport($issuance);
        $issuance->load(['item.category', 'office', 'department', 'issuedBy', 'issuedTo']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $asPdf = $request->query('format') === 'pdf';

        $formLabel = match (true) {
            in_array(strtolower((string) $formSlug), ['par'], true),
            ($formSlug === null || $formSlug === '') && $issuance->item?->category?->getTemplateSlug() === 'ppe' => 'PAR',
            in_array(strtolower((string) $formSlug), ['ics'], true),
            ($formSlug === null || $formSlug === '') && $issuance->item?->category?->getTemplateSlug() === 'semi_expendable' => 'ICS',
            default => 'RSMI',
        };

        $this->logExportActivity(
            ($asPdf ? "Exported {$formLabel} PDF for issuance " : "Exported {$formLabel} for issuance ").$issuance->reference_code,
            $issuance,
            ['form' => $formSlug, 'format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            if ($formLabel === 'RSMI' && $issuance->item?->category?->getTemplateSlug() === 'consumables') {
                return $this->rsmiFastPdfExport->download($issuance);
            }

            return $this->owwaExport->downloadIssuancePdf($issuance, null, $formSlug);
        }

        return $this->owwaExport->downloadIssuance($issuance, null, $formSlug);
    }

    public function issuanceRsmiFastPdf(Issuance $issuance): Response
    {
        $this->authorizeIssuanceExport($issuance);
        $issuance->loadMissing(['item.category', 'office', 'department', 'issuedBy', 'issuedTo', 'batch']);

        abort_unless(
            $issuance->item?->category?->getTemplateSlug() === 'consumables',
            422,
            'Fast RSMI PDF is only available for consumable issuances.',
        );

        $this->logExportActivity(
            'Exported RSMI PDF for issuance '.$issuance->reference_code,
            $issuance,
            ['form' => null, 'format' => 'pdf', 'mode' => 'fast'],
        );

        return $this->rsmiFastPdfExport->download($issuance);
    }

    public function transfer(Request $request, Transfer $transfer): StreamedResponse|Response
    {
        $this->authorizeTransferExport($transfer);
        $transfer->load(['item.category', 'fromOffice', 'toOffice', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported OWWA transfer PDF ' : 'Exported OWWA transfer report ').$transfer->reference_code,
            $transfer,
            ['form' => $formSlug, 'format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return $this->owwaExport->downloadTransferPdf($transfer, null, $formSlug);
        }

        return $this->owwaExport->downloadTransfer($transfer, null, $formSlug);
    }

    public function disposal(Request $request, Disposal $disposal): StreamedResponse|Response
    {
        $this->authorizeDisposalExport($disposal);
        $disposal->load(['item.category', 'office', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported OWWA disposal PDF ' : 'Exported OWWA disposal report ').$disposal->reference_code,
            $disposal,
            ['form' => $formSlug, 'format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return $this->owwaExport->downloadDisposalPdf($disposal, null, $formSlug);
        }

        return $this->owwaExport->downloadDisposal($disposal, null, $formSlug);
    }

    public function requisition(Request $request, Requisition $requisition): StreamedResponse|Response
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User && RequisitionExportActions::userCanExportRis($user),
            403,
        );
        abort_unless($requisition->canExportRis(), 403);

        $requisition->load(['office', 'department', 'requestedBy', 'approvedBy', 'items.item']);

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported RIS PDF ' : 'Exported RIS ').$requisition->reference_code,
            $requisition,
            ['format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return $this->owwaExport->downloadRequisitionPdf($requisition);
        }

        return $this->owwaExport->downloadRequisition($requisition);
    }

    public function item(Request $request, Item $item): StreamedResponse
    {
        $this->authorizeItemExport($item);
        $item->load('category');
        $formSlug = $request->query('form', '');
        $officeId = $request->query('office_id');

        if ($officeId !== null && $officeId !== '') {
            $this->assertCustodianOffice((int) $officeId);
        }

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

    public function physicalCount(Request $request, PhysicalCountSession $physicalCountSession): StreamedResponse|Response
    {
        $this->authorizePhysicalCountExport($physicalCountSession);

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported physical count PDF ' : 'Exported physical count report ').$physicalCountSession->reference_code,
            $physicalCountSession,
            ['format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return match ($physicalCountSession->count_type) {
                PhysicalCountSession::TYPE_RPCI => $this->rpciFastPdfExport->download($physicalCountSession),
                PhysicalCountSession::TYPE_RPCPPE => $this->rpcppeFastPdfExport->download($physicalCountSession),
                PhysicalCountSession::TYPE_RPCSP => $this->rpcspFastPdfExport->download($physicalCountSession),
                default => $this->itemReport->downloadPhysicalCountPdf($physicalCountSession),
            };
        }

        return $this->itemReport->downloadPhysicalCount($physicalCountSession);
    }

    public function physicalCountRpciFastPdf(PhysicalCountSession $physicalCountSession): Response
    {
        $this->authorizePhysicalCountExport($physicalCountSession);

        abort_unless(
            $physicalCountSession->count_type === PhysicalCountSession::TYPE_RPCI,
            422,
            'Fast RPCI PDF is only available for consumable physical count sessions.',
        );

        $this->logExportActivity(
            'Exported RPCI PDF for physical count '.$physicalCountSession->reference_code,
            $physicalCountSession,
            ['format' => 'pdf', 'mode' => 'fast'],
        );

        return $this->rpciFastPdfExport->download($physicalCountSession);
    }

    public function physicalCountRpcppeFastPdf(PhysicalCountSession $physicalCountSession): Response
    {
        $this->authorizePhysicalCountExport($physicalCountSession);

        abort_unless(
            $physicalCountSession->count_type === PhysicalCountSession::TYPE_RPCPPE,
            422,
            'Fast RPCPPE PDF is only available for property, plant and equipment physical count sessions.',
        );

        $this->logExportActivity(
            'Exported RPCPPE PDF for physical count '.$physicalCountSession->reference_code,
            $physicalCountSession,
            ['format' => 'pdf', 'mode' => 'fast'],
        );

        return $this->rpcppeFastPdfExport->download($physicalCountSession);
    }

    public function physicalCountRpcspFastPdf(PhysicalCountSession $physicalCountSession): Response
    {
        $this->authorizePhysicalCountExport($physicalCountSession);

        abort_unless(
            $physicalCountSession->count_type === PhysicalCountSession::TYPE_RPCSP,
            422,
            'Fast RPCSP PDF is only available for semi-expendable physical count sessions.',
        );

        $this->logExportActivity(
            'Exported RPCSP PDF for physical count '.$physicalCountSession->reference_code,
            $physicalCountSession,
            ['format' => 'pdf', 'mode' => 'fast'],
        );

        return $this->rpcspFastPdfExport->download($physicalCountSession);
    }

    public function distribution(Request $request, Distribution $distribution): StreamedResponse|Response
    {
        $this->authorizeDistributionExport($distribution);
        $distribution->load(['item.category', 'office', 'department', 'distributedTo', 'distributedBy']);

        $asPdf = $request->query('format') === 'pdf';

        $this->logExportActivity(
            ($asPdf ? 'Exported OWWA distribution PDF #' : 'Exported OWWA distribution report #').$distribution->getKey(),
            $distribution,
            ['format' => $asPdf ? 'pdf' : 'xlsx'],
        );

        if ($asPdf) {
            return $this->owwaExport->downloadDistributionPdf($distribution);
        }

        return $this->owwaExport->downloadDistribution($distribution);
    }

    public function employeeDistribution(Request $request, User $employee): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isUnitConsolidator(), 403);

        try {
            app(EmployeeDistributionInventoryService::class)->assertUnitConsolidatorCanViewEmployee($user, $employee);
        } catch (AuthorizationException) {
            abort(403);
        }

        $category = (string) $request->query('category', EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES);
        if (! EmployeeDistributionInventoryService::isValidCategory($category)) {
            $category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }

        $custodyTab = (string) $request->query('custody_tab', EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND);
        if (! EmployeeDistributionInventoryService::isValidCustodyTab($custodyTab)) {
            $custodyTab = EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND;
        }

        $item = $request->query('item');
        $itemId = $item !== null && $item !== '' ? (int) $item : null;

        $this->logExportActivity('Exported employee distribution history '.$employee->name, $employee, [
            'category' => $category,
            'custody_tab' => $custodyTab,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'item' => $itemId,
        ]);

        return $this->employeeDistributionExport->download(
            $employee,
            $category,
            $custodyTab,
            is_string($request->query('from')) ? $request->query('from') : null,
            is_string($request->query('to')) ? $request->query('to') : null,
            $itemId,
        );
    }

    public function acquisitionPaperworkPrFastPdf(AcquisitionPaperwork $acquisitionPaperwork): Response
    {
        $this->authorizeAcquisitionPaperworkExport($acquisitionPaperwork);

        $this->logExportActivity(
            'Exported acquisition paperwork PR PDF '.$acquisitionPaperwork->pr_number,
            $acquisitionPaperwork,
        );

        return $this->purchaseRequestFastPdfExport->download($acquisitionPaperwork);
    }

    public function acquisitionPaperworkPrFastExcel(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $this->authorizeAcquisitionPaperworkExport($acquisitionPaperwork);

        $this->logExportActivity(
            'Exported acquisition paperwork PR Excel '.$acquisitionPaperwork->pr_number,
            $acquisitionPaperwork,
        );

        return $this->purchaseRequestFastExcelExport->download($acquisitionPaperwork);
    }

    public function acquisitionPaperworkPo(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $this->authorizeAcquisitionPaperworkExport($acquisitionPaperwork);
        $acquisitionPaperwork->load(['office', 'department', 'itemCategory', 'lines.item', 'purchaseOrder.orderedLines.item']);

        if ($acquisitionPaperwork->purchaseOrder) {
            $this->logExportActivity(
                'Exported purchase order '.$acquisitionPaperwork->purchaseOrder->number,
                $acquisitionPaperwork->purchaseOrder,
            );

            return $this->purchaseOrderFastExcelExport->download($acquisitionPaperwork->purchaseOrder);
        }

        $this->logExportActivity(
            'Exported acquisition paperwork PO '.$acquisitionPaperwork->po_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkPo($acquisitionPaperwork);
    }

    public function acquisitionPaperworkIar(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $this->authorizeAcquisitionPaperworkExport($acquisitionPaperwork);
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

            return $this->inspectionAcceptanceReportFastExcelExport->download($iar);
        }

        $this->logExportActivity(
            'Exported acquisition paperwork IAR '.$acquisitionPaperwork->iar_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkIar($acquisitionPaperwork);
    }

    public function purchaseOrderExcel(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        $this->authorizePurchaseOrderExport($purchaseOrder);
        $this->logExportActivity('Exported purchase order Excel '.$purchaseOrder->number, $purchaseOrder);

        return $this->purchaseOrderFastExcelExport->download($purchaseOrder);
    }

    public function purchaseOrderPdf(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizePurchaseOrderExport($purchaseOrder);
        $this->logExportActivity('Exported purchase order PDF '.$purchaseOrder->number, $purchaseOrder);

        return $this->purchaseOrderFastPdfExport->download($purchaseOrder);
    }

    public function inspectionAcceptanceReportExcel(InspectionAcceptanceReport $inspectionAcceptanceReport): StreamedResponse
    {
        $this->authorizeInspectionAcceptanceReportExport($inspectionAcceptanceReport);
        $this->logExportActivity(
            'Exported IAR Excel '.$inspectionAcceptanceReport->number,
            $inspectionAcceptanceReport,
        );

        return $this->inspectionAcceptanceReportFastExcelExport->download($inspectionAcceptanceReport);
    }

    public function inspectionAcceptanceReportPdf(InspectionAcceptanceReport $inspectionAcceptanceReport): Response
    {
        $this->authorizeInspectionAcceptanceReportExport($inspectionAcceptanceReport);
        $this->logExportActivity(
            'Exported IAR PDF '.$inspectionAcceptanceReport->number,
            $inspectionAcceptanceReport,
        );

        return $this->inspectionAcceptanceReportFastPdfExport->download($inspectionAcceptanceReport);
    }

    /** @deprecated */
    public function procurementPo(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        return $this->acquisitionPaperworkPo($acquisitionPaperwork);
    }

    /** @deprecated */
    public function procurementIar(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        return $this->acquisitionPaperworkIar($acquisitionPaperwork);
    }
}
