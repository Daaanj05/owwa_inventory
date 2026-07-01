@php
    /** @var \App\Models\AcquisitionPaperwork $paperwork */
    $statusClass = $paperwork->isReceived()
        ? 'owwa-pc-status-badge--complete'
        : ($paperwork->isIarApproved() ? 'owwa-pc-status-badge--incomplete' : 'owwa-pc-status-badge--progress');
@endphp

<div class="owwa-pc-view-hero">
    <div class="owwa-pc-view-hero-main">
        <div class="owwa-pc-view-hero-top">
            <div>
                <p class="owwa-pc-stat-label">Case reference</p>
                <h2 class="owwa-pc-view-reference">{{ $paperwork->reference_code ?? '—' }}</h2>
            </div>
            <span @class(['owwa-pc-status-badge', $statusClass])>
                {{ $paperwork->workflowStatusLabel() }}
            </span>
        </div>

        <dl class="owwa-pc-view-meta">
            <div>
                <dt class="owwa-pc-stat-label">Category</dt>
                <dd>{{ $paperwork->itemCategory?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="owwa-pc-stat-label">Office</dt>
                <dd>{{ $paperwork->office?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="owwa-pc-stat-label">Lines</dt>
                <dd>{{ $lineCount }}</dd>
            </div>
        </dl>
    </div>

    <div class="owwa-pc-scan-card owwa-pc-view-progress">
        <div class="owwa-pc-progress-header">
            <span class="owwa-pc-stat-label">Workflow progress</span>
            <span class="owwa-pc-progress-text">{{ $progressPercent }}%</span>
        </div>
        <div class="owwa-pc-progress">
            <div class="owwa-pc-progress-bar" style="width: {{ $progressPercent }}%"></div>
        </div>
        @if ($totalAmount > 0)
            <p class="owwa-pc-scan-hint">Total PO amount: ₱{{ number_format($totalAmount, 2) }}</p>
        @endif
    </div>

    @include('filament.resources.acquisitions.partials.acquisition-workflow-stepper', [
        'workflowSteps' => $workflowSteps,
        'clickable' => true,
        'recordKey' => $paperwork->getKey(),
        'title' => 'Workflow',
        'hint' => 'Click a completed step to review earlier forms. Use Save & submit for export when the current phase is ready.',
    ])

    @if ($custodyReceipts->isNotEmpty())
        <div class="owwa-pc-scan-card">
            <h3 class="owwa-pc-recent-title">Custodian receipts</h3>
            <ul class="owwa-pc-recent-list">
                @foreach ($custodyReceipts as $receipt)
                    <li>
                        <span class="owwa-pc-recent-ref">{{ $receipt->reference_code }}</span>
                        <span class="owwa-pc-line-meta">{{ $receipt->item?->name }} — {{ $receipt->quantity }} @ {{ number_format((float) $receipt->unit_cost, 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
