<?php

namespace App\Support;

use App\Models\Requisition;
use Illuminate\Support\Carbon;

class EmployeeRequisitionViewPresenter
{
    /**
     * @return array<int, array{step: int, label: string, shortLabel: string, description: string, state: string, url: null}>
     */
    public static function workflowSteps(Requisition $record): array
    {
        $record->loadMissing(['compiledIntoRequisition']);

        if ($record->isDraft()) {
            return [
                [
                    'step' => 1,
                    'label' => 'Draft',
                    'shortLabel' => 'Draft',
                    'description' => 'Not yet submitted to your Unit Consolidator',
                    'state' => 'active',
                    'url' => null,
                ],
                [
                    'step' => 2,
                    'label' => 'Reviewed by UC',
                    'shortLabel' => 'Reviewed',
                    'description' => 'Awaiting submission',
                    'state' => 'pending',
                    'url' => null,
                ],
                [
                    'step' => 3,
                    'label' => 'Endorsed to SC',
                    'shortLabel' => 'Endorsed',
                    'description' => 'Not yet endorsed',
                    'state' => 'pending',
                    'url' => null,
                ],
                [
                    'step' => 4,
                    'label' => 'Distributed / Closed',
                    'shortLabel' => 'Closed',
                    'description' => 'Awaiting distribution from your office',
                    'state' => 'pending',
                    'url' => null,
                ],
            ];
        }

        $submittedState = 'done';
        $reviewState = $record->status === Requisition::STATUS_PENDING ? 'pending' : 'done';
        $endorsedState = $record->compiled_into_requisition_id !== null ? 'done' : ($record->status === Requisition::STATUS_ACCEPTED ? 'active' : 'pending');
        $distributedState = $record->closed_at !== null
            ? 'done'
            : ($record->compiled_into_requisition_id !== null ? 'active' : 'pending');

        $distributed = EmployeeRequisitionStatus::distributedTotal($record);
        $requested = EmployeeRequisitionStatus::fulfillmentTargetTotal($record);

        if ($record->status === Requisition::STATUS_REJECTED) {
            $endorsedState = 'pending';
            $distributedState = 'pending';
        }

        $latestDistributionDate = EmployeeRequisitionStatus::latestDistributionDate($record);

        return [
            [
                'step' => 1,
                'label' => 'Submitted',
                'shortLabel' => 'Submitted',
                'description' => self::stepDescription('Request filed', $record->created_at),
                'state' => $submittedState,
                'url' => null,
            ],
            [
                'step' => 2,
                'label' => 'Reviewed by UC',
                'shortLabel' => 'Reviewed',
                'description' => match ($record->status) {
                    Requisition::STATUS_PENDING => 'Awaiting consolidator review',
                    Requisition::STATUS_REJECTED => self::stepDescription('Rejected', $record->approved_at),
                    default => self::stepDescription('Approved by consolidator', $record->approved_at),
                },
                'state' => $reviewState,
                'url' => null,
            ],
            [
                'step' => 3,
                'label' => 'Endorsed to SC',
                'shortLabel' => 'Endorsed',
                'description' => $record->compiled_into_requisition_id !== null
                    ? self::stepDescription('Sent to Supply Custodian', $record->endorsed_at)
                    : 'Not yet endorsed',
                'state' => $endorsedState,
                'url' => null,
            ],
            [
                'step' => 4,
                'label' => 'Distributed / Closed',
                'shortLabel' => 'Closed',
                'description' => match (true) {
                    $record->closed_at !== null => self::stepDescription(
                        $record->fulfillment_summary ?? EmployeeRequisitionStatus::label($record),
                        $record->closed_at,
                    ),
                    $record->status === Requisition::STATUS_REJECTED => 'Not applicable',
                    $distributed > 0 && $distributed < $requested => self::stepDescription(
                        'Partially distributed — awaiting balance',
                        $latestDistributionDate,
                    ),
                    $record->hasBackorderedLines() && $record->compiled_into_requisition_id !== null => 'Awaiting regional stock',
                    default => $latestDistributionDate
                        ? self::stepDescription('Partial distribution recorded', $latestDistributionDate)
                        : 'Awaiting distribution from your office',
                },
                'state' => $distributedState,
                'url' => null,
            ],
        ];
    }

    protected static function stepDescription(string $text, mixed $date): string
    {
        if ($date === null) {
            return $text;
        }

        $formatted = $date instanceof Carbon
            ? $date->format('M j, Y')
            : Carbon::parse($date)->format('M j, Y');

        return "{$text} · {$formatted}";
    }
}
