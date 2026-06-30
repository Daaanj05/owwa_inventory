<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AcquisitionPaperworkCompletionService
{
    public function __construct(
        protected ReferenceCodeService $referenceCodes,
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
        if (! $paperwork->isPrApproved()) {
            return [
                'can_submit' => false,
                'missing_fields' => ['PR must be approved first'],
            ];
        }

        $missing = $paperwork->missingPoFields();

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
        if (! $paperwork->isPoApproved()) {
            return [
                'can_submit' => false,
                'missing_fields' => ['PO must be approved first'],
            ];
        }

        $missing = $paperwork->missingIarFields();

        return [
            'can_submit' => $missing === [],
            'missing_fields' => $missing,
        ];
    }

    public function submitPr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
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

        return $paperwork->fresh();
    }

    public function approvePr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isPrApproved()) {
            throw ValidationException::withMessages(['phase' => 'PR is already approved.']);
        }

        if ($paperwork->pr_status !== AcquisitionPaperwork::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['phase' => 'Submit PR for approval before marking approved.']);
        }

        $paperwork->update([
            'pr_number' => $this->referenceCodes->forAcquisitionPaperworkPr(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'phase' => AcquisitionPaperwork::PHASE_PO,
            'pr_completed_at' => now(),
            'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        return $paperwork->fresh();
    }

    public function submitPo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isPoApproved()) {
            throw ValidationException::withMessages(['phase' => 'PO is already approved.']);
        }

        $evaluation = $this->evaluatePo($paperwork);

        if (! $evaluation['can_submit']) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $evaluation['missing_fields']).'.',
            ]);
        }

        $paperwork->update([
            'po_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'po_submitted_at' => now(),
        ]);

        return $paperwork->fresh();
    }

    public function approvePo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isPoApproved()) {
            throw ValidationException::withMessages(['phase' => 'PO is already approved.']);
        }

        if ($paperwork->po_status !== AcquisitionPaperwork::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['phase' => 'Submit PO for approval before marking approved.']);
        }

        $paperwork->update([
            'po_number' => $this->referenceCodes->forAcquisitionPaperworkPo(),
            'po_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'phase' => AcquisitionPaperwork::PHASE_IAR,
            'po_completed_at' => now(),
            'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        return $paperwork->fresh();
    }

    public function submitIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isIarApproved()) {
            throw ValidationException::withMessages(['phase' => 'IAR is already approved.']);
        }

        $evaluation = $this->evaluateIar($paperwork);

        if (! $evaluation['can_submit']) {
            throw ValidationException::withMessages([
                'phase' => 'Missing: '.implode(', ', $evaluation['missing_fields']).'.',
            ]);
        }

        $paperwork->update([
            'iar_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'iar_submitted_at' => now(),
        ]);

        return $paperwork->fresh();
    }

    public function approveIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        if ($paperwork->isIarApproved()) {
            throw ValidationException::withMessages(['phase' => 'IAR is already approved.']);
        }

        if ($paperwork->iar_status !== AcquisitionPaperwork::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['phase' => 'Submit IAR for approval before marking approved.']);
        }

        $paperwork->update([
            'iar_number' => $this->referenceCodes->forAcquisitionPaperworkIar(),
            'iar_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'phase' => AcquisitionPaperwork::PHASE_IAR,
            'iar_completed_at' => now(),
        ]);

        return $paperwork->fresh();
    }

    /**
     * @return Collection<int, Acquisition>
     */
    public function recordCustodyReceipts(AcquisitionPaperwork $paperwork): Collection
    {
        if (! $paperwork->isIarApproved()) {
            throw ValidationException::withMessages(['phase' => 'IAR must be approved before recording custodian receipt.']);
        }

        if ($paperwork->isReceived()) {
            throw ValidationException::withMessages(['phase' => 'Custodian receipts already recorded for this case.']);
        }

        $paperwork->loadMissing('lines');

        if ($paperwork->lines->isEmpty()) {
            throw ValidationException::withMessages(['phase' => 'Add at least one line item before recording custodian receipt.']);
        }

        $source = trim('PO '.($paperwork->po_number ?? '').' / IAR '.($paperwork->iar_number ?? ''));
        $acquisitionDate = $paperwork->iar_date ?? now();

        $created = collect();

        foreach ($paperwork->lines as $line) {
            $created->push(Acquisition::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'acquisition_paperwork_line_id' => $line->id,
                'item_id' => $line->item_id,
                'office_id' => $paperwork->office_id,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost ?? 0,
                'acquisition_date' => $acquisitionDate,
                'source' => $source,
                'recorded_by' => auth()->id(),
            ]));
        }

        $paperwork->update(['received_at' => now()]);

        return $created;
    }

    /** @deprecated Use submitPr() and approvePr() */
    public function completePr(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitPr($paperwork);

        return $this->approvePr($paperwork->fresh());
    }

    /** @deprecated Use submitPo() and approvePo() */
    public function completePo(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitPo($paperwork);

        return $this->approvePo($paperwork->fresh());
    }

    /** @deprecated Use submitIar() and approveIar() */
    public function completeIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $this->submitIar($paperwork);

        return $this->approveIar($paperwork->fresh());
    }
}
