<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
use Illuminate\Validation\ValidationException;

class AcquisitionPaperworkCompletionService
{
    public function __construct(
        protected ReferenceCodeService $referenceCodes,
        protected PurchaseOrderWorkflowService $purchaseOrders,
        protected InspectionAcceptanceReportWorkflowService $inspectionReports,
    ) {}

    /**
     * @return array{can_submit: bool, missing_fields: array<int, string>}
     */
    public function evaluatePr(AcquisitionPaperwork $paperwork): array
    {
        $missing = $paperwork->missingPrFields();

        return [
            'can_submit' => $missing === [],
            'missing_fields' => $missing,
        ];
    }

    /**
     * @return array{can_submit: bool, missing_fields: array<int, string>}
     */
    public function evaluatePo(AcquisitionPaperwork $paperwork): array
    {
        $purchaseOrder = $paperwork->purchaseOrder;

        if ($purchaseOrder === null) {
            return [
                'can_submit' => false,
                'missing_fields' => $paperwork->isPrApproved()
                    ? ['Create a purchase order from the PO tab']
                    : ['PR must be approved first'],
            ];
        }

        $missing = $purchaseOrder->missingFields();

        return [
            'can_submit' => $missing === [],
            'missing_fields' => $missing,
        ];
    }

    /**
     * @return array{can_submit: bool, missing_fields: array<int, string>}
     */
    public function evaluateIar(AcquisitionPaperwork $paperwork): array
    {
        $iar = $paperwork->purchaseOrder?->inspectionAcceptanceReport;

        if ($iar === null) {
            return [
                'can_submit' => false,
                'missing_fields' => $paperwork->isPoApproved()
                    ? ['Create an IAR from the IAR tab']
                    : ['PO must be approved first'],
            ];
        }

        $missing = $iar->missingFields();

        return [
            'can_submit' => $missing === [],
            'missing_fields' => $missing,
        ];
    }

    public function submitPr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived purchase requests cannot be submitted.']);
        }

        if ($paperwork->isPrApproved()) {
            throw ValidationException::withMessages(['phase' => 'PR is already approved.']);
        }

        $evaluation = $this->evaluatePr($paperwork);

        if (! $evaluation['can_submit']) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $evaluation['missing_fields']).'.',
            ]);
        }

        $paperwork->update([
            'pr_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'pr_submitted_at' => now(),
        ]);

        return $paperwork;
    }

    public function approvePr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isArchived()) {
            throw ValidationException::withMessages(['phase' => 'Archived purchase requests cannot be approved.']);
        }

        if ($paperwork->isPrApproved()) {
            throw ValidationException::withMessages(['phase' => 'PR is already approved.']);
        }

        if ($paperwork->pr_status !== AcquisitionPaperwork::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['phase' => 'Submit PR for approval before marking approved.']);
        }

        $paperwork->update([
            'pr_number' => $this->referenceCodes->forAcquisitionPaperworkPr(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'phase' => AcquisitionPaperwork::PHASE_PR,
            'pr_completed_at' => now(),
        ]);

        return $paperwork;
    }

    public function archive(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $paperwork->update(['archived_at' => now()]);

        return $paperwork;
    }

    public function restore(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $paperwork->update(['archived_at' => null]);

        return $paperwork;
    }

    /** @deprecated Use PurchaseOrderWorkflowService::submit() */
    public function submitPo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $purchaseOrder = $paperwork->purchaseOrder;

        if ($purchaseOrder === null) {
            throw ValidationException::withMessages(['phase' => 'Create a purchase order from an approved PR first.']);
        }

        $this->purchaseOrders->submit($purchaseOrder);

        return $paperwork->fresh() ?? $paperwork;
    }

    /** @deprecated Use PurchaseOrderWorkflowService::approve() */
    public function approvePo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $purchaseOrder = $paperwork->purchaseOrder;

        if ($purchaseOrder === null) {
            throw ValidationException::withMessages(['phase' => 'Create a purchase order from an approved PR first.']);
        }

        $this->purchaseOrders->approve($purchaseOrder);

        return $paperwork->fresh() ?? $paperwork;
    }

    /** @deprecated Use InspectionAcceptanceReportWorkflowService::submit() */
    public function submitIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $iar = $paperwork->purchaseOrder?->inspectionAcceptanceReport;

        if ($iar === null) {
            throw ValidationException::withMessages(['phase' => 'Create an IAR from an approved PO first.']);
        }

        $this->inspectionReports->submit($iar);

        return $paperwork->fresh() ?? $paperwork;
    }

    /** @deprecated Use InspectionAcceptanceReportWorkflowService::approve() */
    public function approveIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $iar = $paperwork->purchaseOrder?->inspectionAcceptanceReport;

        if ($iar === null) {
            throw ValidationException::withMessages(['phase' => 'Create an IAR from an approved PO first.']);
        }

        $this->inspectionReports->approve($iar);

        return $paperwork->fresh() ?? $paperwork;
    }

    /** @deprecated Use InspectionAcceptanceReportWorkflowService::recordCustodyReceipts() */
    public function recordCustodyReceipts(AcquisitionPaperwork $paperwork)
    {
        $iar = $paperwork->purchaseOrder?->inspectionAcceptanceReport;

        if ($iar === null) {
            throw ValidationException::withMessages(['phase' => 'Create and approve an IAR before recording custodian receipt.']);
        }

        return $this->inspectionReports->recordCustodyReceipts($iar);
    }

    /** @deprecated Use submitPr() and approvePr() */
    public function completePr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitPr($paperwork);

        return $this->approvePr($paperwork);
    }

    /** @deprecated Use PurchaseOrderWorkflowService */
    public function completePo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitPo($paperwork);

        return $this->approvePo($paperwork);
    }

    /** @deprecated Use InspectionAcceptanceReportWorkflowService */
    public function completeIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitIar($paperwork);

        return $this->approveIar($paperwork);
    }
}
