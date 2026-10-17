<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiProcurementRunHistoryExportRequest;
use App\Models\AiProcurementRun;
use App\Models\User;
use App\Services\AiProcurementRunPdfExportService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AiProcurementRunExportController extends Controller
{
    public function __construct(
        protected AiProcurementRunPdfExportService $pdfExport,
    ) {}

    public function run(AiProcurementRun $run): Response
    {
        if (Auth::user()?->role !== User::ROLE_SUPPLY_CUSTODIAN) {
            abort(403, 'Only the Supply Custodian may export AI procurement runs.');
        }

        return $this->pdfExport->downloadRun($run);
    }

    public function history(AiProcurementRunHistoryExportRequest $request): Response
    {
        return $this->pdfExport->downloadHistory(
            $request->validated('date_from'),
            $request->validated('date_to'),
            $request->validated('status'),
            $request->boolean('archived'),
        );
    }
}
