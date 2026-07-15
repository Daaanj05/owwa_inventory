<?php

namespace App\Support;

use App\Models\PropertyActionRequest;

class PropertyActionRequestViewPresenter
{
    public static function forRecord(PropertyActionRequest $record): array
    {
        $record->loadMissing(['requestedBy', 'accountableUser', 'office', 'department', 'lines.issuance.item']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: $record->statusLabel(),
            statusClass: match ($record->status) {
                PropertyActionRequest::STATUS_APPROVED, PropertyActionRequest::STATUS_EXECUTED => 'owwa-pc-status-badge--complete',
                PropertyActionRequest::STATUS_REJECTED => 'owwa-pc-status-badge--incomplete',
                default => 'owwa-pc-status-badge--progress',
            },
            meta: self::heroMeta($record),
            workflowSteps: self::workflowSteps($record),
            hint: 'Review property return details below.',
            workflowTitle: 'Workflow',
        );

        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    protected static function heroMeta(PropertyActionRequest $record): array
    {
        return [
            ['label' => 'Action', 'value' => $record->actionTypeLabel()],
            ['label' => 'Reason', 'value' => $record->reasonLabel()],
            ['label' => 'Requested by', 'value' => $record->requestedBy?->name ?? '—'],
            ['label' => 'Accountable UC', 'value' => $record->accountableUser?->name ?? '—'],
            ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
            ['label' => 'Department', 'value' => $record->department?->name ?? '—'],
            ['label' => 'Date filed', 'value' => $record->created_at?->format('M j, Y') ?? '—'],
        ];
    }

    public static function workflowSteps(PropertyActionRequest $record): array
    {
        $draftState = $record->status === PropertyActionRequest::STATUS_DRAFT ? 'active' : 'done';
        $ucReviewState = match ($record->status) {
            PropertyActionRequest::STATUS_DRAFT => 'pending',
            PropertyActionRequest::STATUS_PENDING_UC => 'active',
            default => 'done',
        };
        $scReviewState = match ($record->status) {
            PropertyActionRequest::STATUS_DRAFT, PropertyActionRequest::STATUS_PENDING_UC => 'pending',
            PropertyActionRequest::STATUS_PENDING_SC => 'active',
            default => 'done',
        };
        $decisionState = match ($record->status) {
            PropertyActionRequest::STATUS_APPROVED, PropertyActionRequest::STATUS_EXECUTED => 'done',
            PropertyActionRequest::STATUS_REJECTED => 'done',
            default => 'pending',
        };

        return [
            ['step' => 1, 'label' => 'Draft', 'shortLabel' => 'Draft', 'description' => $record->status === PropertyActionRequest::STATUS_DRAFT ? 'In progress' : 'Saved', 'state' => $draftState, 'url' => null],
            ['step' => 2, 'label' => 'UC Review', 'shortLabel' => 'UC', 'description' => $record->status === PropertyActionRequest::STATUS_PENDING_UC ? 'Awaiting UC' : ($record->status === PropertyActionRequest::STATUS_DRAFT ? 'Pending' : 'Reviewed'), 'state' => $ucReviewState, 'url' => null],
            ['step' => 3, 'label' => 'SC Review', 'shortLabel' => 'SC', 'description' => $record->status === PropertyActionRequest::STATUS_PENDING_SC ? 'Awaiting SC' : ($record->status === PropertyActionRequest::STATUS_DRAFT || $record->status === PropertyActionRequest::STATUS_PENDING_UC ? 'Pending' : 'Reviewed'), 'state' => $scReviewState, 'url' => null],
            ['step' => 4, 'label' => 'Decision', 'shortLabel' => 'Done', 'description' => match ($record->status) {
                PropertyActionRequest::STATUS_APPROVED => 'Approved',
                PropertyActionRequest::STATUS_EXECUTED => 'Executed',
                PropertyActionRequest::STATUS_REJECTED => 'Rejected',
                default => 'Pending',
            }, 'state' => $decisionState, 'url' => null],
        ];
    }
}
