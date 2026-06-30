<?php

namespace App\Support;

use App\Models\Requisition;
use App\Services\RequisitionFulfillmentService;

class RequisitionViewPresenter
{
    public static function forRecord(Requisition $record): array
    {
        $record->loadMissing(['requestedBy', 'office', 'department', 'items.item']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: RequisitionStatus::label($record->status),
            statusClass: match ($record->status) {
                Requisition::STATUS_ACCEPTED => 'owwa-pc-status-badge--complete',
                Requisition::STATUS_REJECTED => 'owwa-pc-status-badge--incomplete',
                default => 'owwa-pc-status-badge--progress',
            },
            meta: [
                ['label' => 'Requested by', 'value' => $record->requestedBy?->name ?? '—'],
                ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
                ['label' => 'Department', 'value' => $record->department?->name ?? '—'],
                ['label' => 'Date filed', 'value' => $record->created_at?->format('M j, Y') ?? '—'],
            ],
            workflowSteps: self::workflowSteps($record),
            hint: 'Use actions below to issue, export, or action this requisition.',
            workflowTitle: 'Workflow',
        );

        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    public static function workflowSteps(Requisition $record): array
    {
        $fulfillment = app(RequisitionFulfillmentService::class);
        $totalRemaining = $record->items->sum(
            fn ($item): int => $fulfillment->remainingQuantity($item)
        );
        $hasIssuance = $record->issuances()->exists();

        $reviewState = $record->status === Requisition::STATUS_PENDING ? 'active' : 'done';
        $decisionState = match ($record->status) {
            Requisition::STATUS_PENDING => 'active',
            Requisition::STATUS_ACCEPTED, Requisition::STATUS_REJECTED => 'done',
            default => 'pending',
        };
        $fulfillmentState = match (true) {
            $record->status === Requisition::STATUS_REJECTED => 'pending',
            $record->status === Requisition::STATUS_ACCEPTED && $totalRemaining === 0 && $hasIssuance => 'done',
            $record->status === Requisition::STATUS_ACCEPTED => 'active',
            default => 'pending',
        };

        return [
            ['step' => 1, 'label' => 'File', 'shortLabel' => 'File', 'description' => 'Requisition submitted', 'state' => 'done', 'url' => null],
            ['step' => 2, 'label' => 'Review', 'shortLabel' => 'Review', 'description' => $record->status === Requisition::STATUS_PENDING ? 'Awaiting action' : 'Review complete', 'state' => $reviewState, 'url' => null],
            ['step' => 3, 'label' => 'Decision', 'shortLabel' => 'Decision', 'description' => match ($record->status) {
                Requisition::STATUS_ACCEPTED => 'Accepted',
                Requisition::STATUS_REJECTED => 'Rejected',
                default => 'Pending decision',
            }, 'state' => $decisionState, 'url' => null],
            ['step' => 4, 'label' => 'Fulfillment', 'shortLabel' => 'Issue', 'description' => match (true) {
                $record->status === Requisition::STATUS_REJECTED => 'Not applicable',
                $totalRemaining === 0 && $hasIssuance => 'Fully issued',
                $hasIssuance => "{$totalRemaining} unit(s) remaining",
                default => 'Issue from custodian actions',
            }, 'state' => $fulfillmentState, 'url' => null],
        ];
    }
}
