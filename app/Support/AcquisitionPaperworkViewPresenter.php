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
        $poState = $paperwork->isPoApproved()
            ? 'done'
            : ($paperwork->isPrApproved()
                ? (($paperwork->purchaseOrder?->isPendingApproval() ?? $paperwork->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL)
                    || $poEval['can_submit']
                    || $paperwork->purchaseOrder !== null
                        ? 'active'
                        : 'pending')
                : 'pending');
        $iarState = $paperwork->isIarApproved()
            ? 'done'
            : ($paperwork->isPoApproved()
                ? (($paperwork->purchaseOrder?->inspectionAcceptanceReport?->isPendingApproval()
                    ?? $paperwork->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL)
                    || $iarEval['can_submit']
                    || $paperwork->purchaseOrder?->inspectionAcceptanceReport !== null
                        ? 'active'
                        : 'pending')
                : 'pending');
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
                    : (filled($paperwork->pr_number)
                        ? 'PR '.$paperwork->pr_number.' — export for signature, then mark Approved'
                        : ($prEval['can_submit'] ? 'Fill PR and save to assign PR No.' : 'Fill PR header and item lines')),
                'state' => $prState,
                'actionKey' => 'viewPr',
                'navigable' => $paperwork->isPrApproved()
                    || filled($paperwork->pr_number)
                    || $paperwork->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
                'url' => $paperwork->isPrApproved() ? route('owwa.export.acquisition-paperwork.pr', $paperwork) : null,
            ],
            [
                'step' => 2,
                'label' => 'Purchase order',
                'shortLabel' => 'PO',
                'statusLabel' => $paperwork->phaseStatusLabel(AcquisitionPaperwork::PHASE_PO),
                'description' => $paperwork->isPoApproved()
                    ? 'PO '.$paperwork->po_number.' — approved'
                    : ($paperwork->isPrApproved()
                        ? (filled($paperwork->po_number)
                            ? 'PO '.$paperwork->po_number.' — export for signature, then mark Approved'
                            : 'Enter supplier and costs')
                        : 'Unlocks after PR approval'),
                'state' => $poState,
                'actionKey' => 'viewPo',
                'navigable' => $paperwork->isPoApproved()
                    || filled($paperwork->po_number)
                    || $paperwork->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
                'url' => $paperwork->isPoApproved() ? route('owwa.export.acquisition-paperwork.po', $paperwork) : null,
            ],
            [
                'step' => 3,
                'label' => 'Inspection & acceptance',
                'shortLabel' => 'IAR',
                'statusLabel' => $paperwork->phaseStatusLabel(AcquisitionPaperwork::PHASE_IAR),
                'description' => $paperwork->isIarApproved()
                    ? 'IAR '.$paperwork->iar_number.' — approved'
                    : ($paperwork->isPoApproved()
                        ? (filled($paperwork->iar_number)
                            ? 'IAR '.$paperwork->iar_number.' — export for signature, then mark Approved'
                            : 'Record inspection signatories')
                        : 'Unlocks after PO approval'),
                'state' => $iarState,
                'actionKey' => 'viewIar',
                'navigable' => $paperwork->isIarApproved()
                    || filled($paperwork->iar_number)
                    || $paperwork->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
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
                'navigable' => false,
                'url' => null,
            ],
        ];
    }

    public static function currentEditPhase(?AcquisitionPaperwork $record, string $operation = 'edit'): ?string
    {
        if ($operation === 'create' || $record === null) {
            return AcquisitionPaperwork::PHASE_PR;
        }

        if ($record->isReceived()) {
            return null;
        }

        if (! $record->isPrApproved()) {
            return AcquisitionPaperwork::PHASE_PR;
        }

        if (! $record->isPoApproved()) {
            return AcquisitionPaperwork::PHASE_PO;
        }

        if (! $record->isIarApproved()) {
            return AcquisitionPaperwork::PHASE_IAR;
        }

        return null;
    }

    public static function isCurrentPhasePending(?AcquisitionPaperwork $record): bool
    {
        if ($record === null) {
            return false;
        }

        return match (self::currentEditPhase($record)) {
            AcquisitionPaperwork::PHASE_PR => $record->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            AcquisitionPaperwork::PHASE_PO => $record->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            AcquisitionPaperwork::PHASE_IAR => $record->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            default => false,
        };
    }

    public static function editModalHeading(AcquisitionPaperwork $record): string
    {
        return match (self::currentEditPhase($record)) {
            AcquisitionPaperwork::PHASE_PR => 'Edit purchase request',
            AcquisitionPaperwork::PHASE_PO => 'Edit purchase order',
            AcquisitionPaperwork::PHASE_IAR => 'Edit inspection & acceptance',
            default => 'Edit acquisition',
        };
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
     * @return array{paperwork: AcquisitionPaperwork, progressPercent: int, workflowSteps: array, lineCount: int, totalAmount: float, showReceipts: bool, itemRows: array<int, array<string, mixed>>}
     */
    public static function forPaperwork(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing(['office', 'itemCategory', 'lines.item.category', 'acquisitions.item']);

        return [
            'paperwork' => $paperwork,
            'progressPercent' => self::progressPercent($paperwork),
            'workflowSteps' => self::workflowSteps($paperwork),
            'lineCount' => $paperwork->lines->count(),
            'totalAmount' => $paperwork->totalAmount(),
            'showReceipts' => $paperwork->isReceived(),
            'itemRows' => self::itemRows($paperwork),
        ];
    }

    /**
     * Pair paperwork lines with posted custodian receipts (1:1 after receive).
     *
     * @return array<int, array{
     *     stock_no: string,
     *     description: string,
     *     quantity: int,
     *     unit_cost: float,
     *     amount: float,
     *     receipt_ref: string|null,
     *     receipt_date: string|null
     * }>
     */
    public static function itemRows(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing(['lines.item.category', 'acquisitions']);

        $receiptsByLineId = $paperwork->acquisitions
            ->keyBy(fn ($acquisition): int => (int) ($acquisition->acquisition_paperwork_line_id ?? 0));

        return $paperwork->lines->map(function ($line) use ($receiptsByLineId): array {
            $receipt = $receiptsByLineId->get((int) $line->id);

            return [
                'stock_no' => $line->stockNumber(),
                'description' => (string) ($line->description ?: $line->item?->name ?: '—'),
                'quantity' => (int) $line->quantity,
                'unit_cost' => (float) ($line->unit_cost ?? 0),
                'amount' => (float) ($line->amount ?? 0),
                'receipt_ref' => $receipt?->reference_code,
                'receipt_date' => $receipt?->acquisition_date?->format('M d, Y'),
            ];
        })->values()->all();
    }

    /**
     * Posted custodian receipts only (Received tab).
     *
     * @return array<int, array{
     *     stock_no: string,
     *     description: string,
     *     quantity: int,
     *     unit_cost: float,
     *     amount: float,
     *     receipt_ref: string|null,
     *     receipt_date: string|null
     * }>
     */
    public static function receivedItemRows(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing(['acquisitions.item.category', 'acquisitions.acquisitionPaperworkLine.item.category']);

        return $paperwork->acquisitions->map(function ($receipt): array {
            $line = $receipt->acquisitionPaperworkLine;
            $item = $receipt->item ?? $line?->item;

            return [
                'stock_no' => $line?->stockNumber()
                    ?: (string) (app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierForItem($item) ?? $item?->item_code ?? ''),
                'description' => (string) ($line?->description ?: $item?->name ?: '—'),
                'quantity' => (int) $receipt->quantity,
                'unit_cost' => (float) ($receipt->unit_cost ?? $line?->unit_cost ?? 0),
                'amount' => (float) (($receipt->unit_cost ?? $line?->unit_cost ?? 0) * (int) $receipt->quantity),
                'receipt_ref' => $receipt->reference_code,
                'receipt_date' => $receipt->acquisition_date?->format('M d, Y'),
            ];
        })->values()->all();
    }
}
