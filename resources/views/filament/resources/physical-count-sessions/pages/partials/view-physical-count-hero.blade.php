@php
    /** @var \App\Models\PhysicalCountSession $session */
    $scanOnly = $summary['scan_only'] ?? false;
    $statusClass = match ($session->status) {
        \App\Models\PhysicalCountSession::STATUS_COMPLETE => 'owwa-pc-status-badge--complete',
        \App\Models\PhysicalCountSession::STATUS_INCOMPLETE => 'owwa-pc-status-badge--incomplete',
        default => 'owwa-pc-status-badge--progress',
    };
@endphp

<div class="owwa-pc-view-hero">
    <div class="owwa-pc-view-hero-main">
        <div class="owwa-pc-view-hero-top">
            <div>
                <p class="owwa-pc-stat-label">Reference</p>
                <h2 class="owwa-pc-view-reference">{{ $session->reference_code ?? '—' }}</h2>
            </div>
            <span @class(['owwa-pc-status-badge', $statusClass])>
                {{ match ($session->status) {
                    \App\Models\PhysicalCountSession::STATUS_IN_PROGRESS => 'In progress',
                    \App\Models\PhysicalCountSession::STATUS_INCOMPLETE => 'Incomplete',
                    \App\Models\PhysicalCountSession::STATUS_COMPLETE => 'Complete',
                    default => ucfirst($session->status),
                } }}
            </span>
        </div>

        <dl class="owwa-pc-view-meta">
            <div>
                <dt class="owwa-pc-stat-label">Form</dt>
                <dd>{{ match ($session->count_type) {
                    'rpcppe' => 'RPCPPE',
                    'rpcsp' => 'RPCSP',
                    default => strtoupper($session->count_type),
                } }}</dd>
            </div>
            <div>
                <dt class="owwa-pc-stat-label">Office</dt>
                <dd>{{ $session->office?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="owwa-pc-stat-label">As at</dt>
                <dd>{{ $session->count_date?->format('M j, Y') ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    @if ($session->supportsQrScanning())
        <div class="owwa-pc-scan-card owwa-pc-view-progress">
            @if ($scanOnly)
                <p class="owwa-pc-scan-mode-label">Scan mode</p>
                <dl class="owwa-pc-stat-grid owwa-pc-stat-grid--single">
                    <div>
                        <dt class="owwa-pc-stat-label">Tags scanned</dt>
                        <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                    </div>
                </dl>
                <p class="owwa-pc-scan-hint">Load expected assets to reconcile against the book list.</p>
            @else
                <div class="owwa-pc-progress-header">
                    <span class="owwa-pc-stat-label">Reconcile progress</span>
                    <span class="owwa-pc-progress-text">{{ $summary['scanned'] }} / {{ $summary['expected'] }} ({{ $progressPercent }}%)</span>
                </div>
                <div class="owwa-pc-progress">
                    <div class="owwa-pc-progress-bar" style="width: {{ $progressPercent }}%"></div>
                </div>
                <dl class="owwa-pc-stat-grid">
                    <div>
                        <dt class="owwa-pc-stat-label">Expected</dt>
                        <dd class="owwa-pc-stat-value">{{ $summary['expected'] }}</dd>
                    </div>
                    <div>
                        <dt class="owwa-pc-stat-label">Scanned</dt>
                        <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                    </div>
                    <div>
                        <dt class="owwa-pc-stat-label">Shortages</dt>
                        <dd class="owwa-pc-stat-value owwa-pc-stat-value--danger">{{ $summary['shortages'] }}</dd>
                    </div>
                    <div>
                        <dt class="owwa-pc-stat-label">Overages</dt>
                        <dd class="owwa-pc-stat-value owwa-pc-stat-value--warning">{{ $summary['overages'] }}</dd>
                    </div>
                </dl>
            @endif
        </div>

        <div class="owwa-pc-scan-card owwa-pc-view-workflow">
            <h3 class="owwa-pc-recent-title">Workflow</h3>
            <ol class="owwa-pc-phase-stepper" aria-label="Physical count workflow">
                @foreach ($workflowSteps as $step)
                    <li @class([
                        'owwa-pc-phase-step',
                        'owwa-pc-phase-step--done' => $step['state'] === 'done',
                        'owwa-pc-phase-step--active' => $step['state'] === 'active',
                    ])>
                        <div class="owwa-pc-phase-step-indicator" aria-hidden="true">
                            <span class="owwa-pc-phase-step-number">{{ $step['step'] }}</span>
                        </div>
                        <div class="owwa-pc-phase-step-body">
                            <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--full">{{ $step['label'] }}</span>
                            <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--short">{{ $step['shortLabel'] ?? $step['label'] }}</span>
                            <p class="owwa-pc-line-meta">{{ $step['description'] }}</p>
                            @if ($step['url'])
                                <a href="{{ $step['url'] }}" class="owwa-pc-workflow-link">Open</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="owwa-pc-scan-hint">Use actions below for Load expected assets, Mark complete, and Export.</p>
        </div>
    @endif
</div>
