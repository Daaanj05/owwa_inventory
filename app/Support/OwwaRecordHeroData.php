<?php

namespace App\Support;

class OwwaRecordHeroData
{
    /**
     * @param  array<int, array{label: string, value: string}>  $meta
     * @param  array<int, array{label: string, value: string|int, class?: string}>  $kpis
     * @param  array<int, array{step: int, label: string, shortLabel: string, description: string, state: string, url: ?string}>  $workflowSteps
     * @param  array{label: string, percent: int, text: string}|null  $progress
     */
    public static function make(
        string $reference,
        string $statusLabel,
        string $statusClass = 'owwa-pc-status-badge--progress',
        array $meta = [],
        array $kpis = [],
        array $workflowSteps = [],
        ?array $progress = null,
        ?string $hint = null,
        ?string $workflowTitle = null,
    ): array {
        return [
            'reference' => $reference,
            'statusLabel' => $statusLabel,
            'statusClass' => $statusClass,
            'meta' => $meta,
            'kpis' => $kpis,
            'workflowSteps' => $workflowSteps,
            'progress' => $progress,
            'hint' => $hint,
            'workflowTitle' => $workflowTitle,
        ];
    }
}
