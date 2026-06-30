<?php

namespace App\Support;

use App\Models\AcquisitionPaperwork;
use App\Services\AcquisitionPaperworkCompletionService;

class AcquisitionPaperworkViewPresenter
{
    /**
     * @return array<int, array{label: string, shortLabel: string, description: string, state: string, statusLabel: string, actionKey: string, url: ?string, step: int}>
     */
    public static function workflowSteps(AcquisitionPaperwork $paperwork): array
    {
        $prEval = app(AcquisitionPaperworkCompletionService::class)->evaluatePr($paperwork);
        $poEval = app(AcquisitionPaperworkCompletionService::class)->evaluatePo($paperwork);
        $iarEval = app(AcquisitionPaperworkCompletionService::class)->evaluateIar($paperwork);

        $prState = $paperwork->isPrApproved() ? 'done' : ($paperwork->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL ? 'active' : ($prEval['can_submit'] ? 'active' : 'pending'));
        $poState = $paperwork->isPoApproved() ? 'done' : ($paperwork->isPrApproved() ? ($paperwork->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL || $poEval['can_submit'] ? 'active' : 'pending') : 'pending');
        $iarState = $paperwork->isIarApproved() ? 'done' : ($paperwork->isPoApproved() ? ($paperwork->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL || $iarEval['can_submit'] ? 'active' : 'pending') : 'pending');
        $receivedState = $paperwork->isReceived()
            ? 'done'
            : ($paperwork->isIarApproved() ? 'active' : 'pending');

        return [
            [
                'step' => 1,
                'label' => 'Purchase request',
                'shortLabel' => 'PR',
                'statusLabel' => $paperwork->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR),
                'description' => $paperwork->isPrApproved()
                    ? 'PR '.$paperwork->pr_number.' — approved'
                    : ($paperwork->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL
                        ? 'PR submitted — mark approved after offline sign-off'
                        : ($prEval['can_submit'] ? 'Fill PR and submit for approval' : 'Fill PR header and item lines')),
                'state' => $prState,
                'actionKey' => 'viewPr',
                'url' => $paperwork->isPrApproved() ? route('owwa.export.acquisition-paperwork.pr', $paperwork) : null,
            ],
            [
                'step' => 2,
                'label' => 'Purchase order',
                'shortLabel' => 'PO',
                'statusLabel' => $paperwork->phaseStatusLabel(AcquisitionPaperwork::PHASE_PO),
                'description' => $paperwork->isPoApproved()
                    ? 'PO '.$paperwork->po_number.' — approved'
                    : ($paperwork->isPrApproved() ? 'Enter supplier and costs' : 'Unlocks after PR approval'),
                'state' => $poState,
                'actionKey' => 'viewPo',
                'url' => $paperwork->isPoApproved() ? route('owwa.export.acquisition-paperwork.po', $paperwork) : null,
            ],
            [
                'step' => 3,
                'label' => 'Inspection & acceptance',
                'shortLabel' => 'IAR',
                'statusLabel' => $paperwork->phaseStatusLabel(AcquisitionPaperwork::PHASE_IAR),
                'description' => $paperwork->isIarApproved()
                    ? 'IAR '.$paperwork->iar_number.' — approved'
                    : ($paperwork->isPoApproved() ? 'Record inspection signatories' : 'Unlocks after PO approval'),
                'state' => $iarState,
                'actionKey' => 'viewIar',
                'url' => $paperwork->isIarApproved() ? route('owwa.export.acquisition-paperwork.iar', $paperwork) : null,
            ],
            [
                'step' => 4,
                'label' => 'Custodian receipt',
                'shortLabel' => 'Received',
                'statusLabel' => $paperwork->isReceived() ? 'Received' : ($paperwork->isIarApproved() ? 'Pending' : 'Locked'),
                'description' => $paperwork->isReceived()
                    ? 'Custodian receipts recorded — stock updated'
                    : ($paperwork->isIarApproved()
                        ? 'Record custodian receipt when goods arrive'
                        : 'Unlocks after IAR approval'),
                'state' => $receivedState,
                'actionKey' => null,
                'url' => null,
            ],
        ];
    }

    public static function workflowStepsForForm(?AcquisitionPaperwork $record): array
    {
        $paperwork = $record ?? new AcquisitionPaperwork([
            'phase' => AcquisitionPaperwork::PHASE_PR,
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        $steps = self::workflowSteps($paperwork);

        if (! $paperwork->exists && ($steps[0]['state'] ?? '') !== 'done') {
            $steps[0]['state'] = 'active';
        }

        return $steps;
    }

    public static function progressPercent(AcquisitionPaperwork $paperwork): int
    {
        $completed = 0;

        if ($paperwork->isPrApproved()) {
            $completed++;
        }

        if ($paperwork->isPoApproved()) {
            $completed++;
        }

        if ($paperwork->isIarApproved()) {
            $completed++;
        }

        if ($paperwork->isReceived()) {
            $completed++;
        }

        return (int) round(($completed / 4) * 100);
    }

    /**
     * @return array{paperwork: AcquisitionPaperwork, progressPercent: int, workflowSteps: array, lineCount: int, totalAmount: float, custodyReceipts: \Illuminate\Support\Collection}
     */
    public static function forPaperwork(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing(['office', 'itemCategory', 'lines.item', 'acquisitions.item']);

        return [
            'paperwork' => $paperwork,
            'progressPercent' => self::progressPercent($paperwork),
            'workflowSteps' => self::workflowSteps($paperwork),
            'lineCount' => $paperwork->lines->count(),
            'totalAmount' => $paperwork->totalAmount(),
            'custodyReceipts' => $paperwork->acquisitions,
        ];
    }
}
