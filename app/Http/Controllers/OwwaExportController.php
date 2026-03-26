<?php

namespace App\Http\Controllers;

use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Services\OwwaTemplateExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaExportController extends Controller
{
    public function __construct(
        protected OwwaTemplateExportService $owwaExport
    ) {}

    public function issuance(Request $request, Issuance $issuance): StreamedResponse
    {
        $issuance->load(['item.category', 'office', 'department', 'issuedBy', 'issuedTo']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        return $this->owwaExport->downloadIssuance($issuance, null, $formSlug);
    }

    public function transfer(Request $request, Transfer $transfer): StreamedResponse
    {
        $transfer->load(['item.category', 'fromOffice', 'toOffice', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        return $this->owwaExport->downloadTransfer($transfer, null, $formSlug);
    }

    public function disposal(Request $request, Disposal $disposal): StreamedResponse
    {
        $disposal->load(['item.category', 'office', 'recordedBy']);

        $formSlug = $request->query('form');
        if ($formSlug === '') {
            $formSlug = null;
        }

        return $this->owwaExport->downloadDisposal($disposal, null, $formSlug);
    }

    public function requisition(Requisition $requisition): StreamedResponse
    {
        $requisition->load(['office', 'department', 'requestedBy', 'approvedBy', 'items.item']);

        return $this->owwaExport->downloadRequisition($requisition);
    }
}
