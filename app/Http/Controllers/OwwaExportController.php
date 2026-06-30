<?php

namespace App\Http\Controllers;

use App\Http\Concerns\LogsExportActivity;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaExportController extends Controller
{
    use LogsExportActivity;

    public function __construct(
        protected OwwaTemplateExportService $owwaExport,
        protected OwwaItemReportService $itemReport,
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

    public function acquisitionPaperworkPo(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $acquisitionPaperwork->load(['office', 'department', 'itemCategory', 'lines.item']);

        $this->logExportActivity(
            'Exported acquisition paperwork PO '.$acquisitionPaperwork->po_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkPo($acquisitionPaperwork);
    }

    public function acquisitionPaperworkIar(AcquisitionPaperwork $acquisitionPaperwork): StreamedResponse
    {
        $acquisitionPaperwork->load(['office', 'department', 'itemCategory', 'lines.item']);

        $this->logExportActivity(
            'Exported acquisition paperwork IAR '.$acquisitionPaperwork->iar_number,
            $acquisitionPaperwork,
        );

        return $this->owwaExport->downloadAcquisitionPaperworkIar($acquisitionPaperwork);
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
