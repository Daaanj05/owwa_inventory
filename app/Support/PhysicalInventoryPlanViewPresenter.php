<?php

namespace App\Support;

use App\Models\PhysicalInventoryPlan;

class PhysicalInventoryPlanViewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forPlan(PhysicalInventoryPlan $record): array
    {
        $counts = $record->progressCounts();
        $percent = $record->progressPercent();

        $statusLabel = match ($record->status) {
            PhysicalInventoryPlan::STATUS_DRAFT => 'Draft',
            PhysicalInventoryPlan::STATUS_APPROVED => 'Approved',
            PhysicalInventoryPlan::STATUS_IN_PROGRESS => 'In progress',
            PhysicalInventoryPlan::STATUS_COMPLETED => 'Completed',
            PhysicalInventoryPlan::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst((string) $record->status),
        };

        $statusClass = match ($record->status) {
            PhysicalInventoryPlan::STATUS_COMPLETED => 'owwa-pc-status-badge--complete',
            PhysicalInventoryPlan::STATUS_IN_PROGRESS => 'owwa-pc-status-badge--progress',
            PhysicalInventoryPlan::STATUS_APPROVED => 'owwa-pc-status-badge--progress',
            default => 'owwa-pc-status-badge--pending',
        };

        return OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: $statusLabel,
            statusClass: $statusClass,
            meta: [
                ['label' => 'Title', 'value' => $record->title],
            ],
            progress: [
                'label' => 'Plan progress',
                'percent' => $percent,
                'text' => "{$counts['completed']} of {$counts['total']} complete",
            ],
        );
    }
}
