@php
    /** @var \App\Models\PhysicalInventoryPlan $record */
    $counts = $record->progressCounts();
    $percent = $record->progressPercent();
    $completed = $counts['completed'];
    $total = $counts['total'];
@endphp

<div class="owwa-inventory-plan-progress" title="{{ $completed }} of {{ $total }} complete">
    <div class="owwa-inventory-plan-progress-track" aria-hidden="true">
        <div
            class="owwa-inventory-plan-progress-fill{{ $record->isCompleted() ? ' owwa-inventory-plan-progress-fill--complete' : '' }}"
            style="width: {{ $percent }}%;"
        ></div>
    </div>
    <span class="owwa-inventory-plan-progress-label">{{ $completed }} / {{ $total }}</span>
</div>
