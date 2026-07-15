<?php

namespace App\Support;

use App\Models\Requisition;
use App\Services\RequisitionFulfillmentService;
use Illuminate\Support\Facades\Auth;

class RequisitionViewPresenter
{
    public static function forRecord(Requisition $record): array
    {
        $record->loadMissing(['requestedBy', 'office', 'department', 'items.item', 'compiledIntoRequisition']);

        $isEmployeeRequest = $record->isEmployeeRequest();

        $hero = OwwaRecordHeroData::make(
            reference: $isEmployeeRequest
                ? ($record->displayTransactionNumber() ?? '—')
                : ($record->reference_code ?? '—'),
            statusLabel: $isEmployeeRequest
                ? EmployeeRequisitionStatus::label($record)
                : RequisitionStatus::label($record->status),
            statusClass: $isEmployeeRequest
                ? EmployeeRequisitionStatus::heroStatusClass($record)
                : match ($record->status) {
                    Requisition::STATUS_ACCEPTED => 'owwa-pc-status-badge--complete',
                    Requisition::STATUS_REJECTED => 'owwa-pc-status-badge--incomplete',
                    default => 'owwa-pc-status-badge--progress',
                },
            meta: self::heroMeta($record, $isEmployeeRequest),
            workflowSteps: $isEmployeeRequest
                ? EmployeeRequisitionViewPresenter::workflowSteps($record)
                : self::workflowSteps($record),
            hint: $isEmployeeRequest
                ? 'Track your request status and endorsed RIS number below.'
                : 'Use actions below to issue, export, or action this requisition.',
            workflowTitle: 'Workflow',
        );

        $hero['referenceLabel'] = $isEmployeeRequest
            ? OwwaReferenceLabels::employeeRequisitionTransaction()
            : OwwaReferenceLabels::requisition();

        return $hero;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    protected static function heroMeta(Requisition $record, bool $isEmployeeRequest): array
    {
        $meta = [
            ['label' => 'Requested by', 'value' => $record->requestedBy?->name ?? '—'],
            ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
            ['label' => 'Department', 'value' => $record->department?->name ?? '—'],
            ['label' => 'Date filed', 'value' => $record->created_at?->format('M j, Y') ?? '—'],
        ];

        if ($isEmployeeRequest) {
            array_unshift($meta, [
                'label' => OwwaReferenceLabels::requisition(),
                'value' => $record->displayRisNumber() ?? '—',
            ]);
        }

        return $meta;
    }

    public static function workflowSteps(Requisition $record): array
    {
        $fulfillment = app(RequisitionFulfillmentService::class);
        $totalRemaining = $record->items->sum(
            fn ($item): int => $fulfillment->remainingQuantity($item)
        );
        $hasIssuance = $record->issuances()->exists()
            || ($record->compiledIntoRequisition?->issuances()->exists() ?? false);

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

    public static function isEmployeeViewer(Requisition $record): bool
    {
        $viewer = Auth::user();

        return $record->isEmployeeRequest()
            && $viewer?->isEmployee()
            && (int) $viewer->id === (int) $record->requested_by;
    }
}
