@php
    $workflowTitle = $workflowTitle ?? 'Workflow';
    $hasWorkflow = count($workflowSteps ?? []) > 0;
    $hasKpis = count($kpis ?? []) > 0;
    $hasProgress = filled($progress ?? null);
@endphp

<div class="owwa-pc-view-hero owwa-record-hero">
    <div class="owwa-pc-view-hero-main">
        <div class="owwa-pc-view-hero-top">
            <div>
                @if (filled($referenceLabel ?? null))
                    <p class="owwa-pc-stat-label">{{ $referenceLabel }}</p>
                @endif
                <h2 class="owwa-pc-view-reference">{{ $reference ?? '—' }}</h2>
            </div>
            @if (filled($statusLabel ?? null))
                <span @class(['owwa-pc-status-badge', $statusClass ?? 'owwa-pc-status-badge--progress'])>
                    {{ $statusLabel }}
                </span>
            @endif
        </div>

        @if (count($meta ?? []) > 0)
            <dl class="owwa-pc-view-meta">
                @foreach ($meta as $item)
                    <div>
                        <dt class="owwa-pc-stat-label">{{ $item['label'] }}</dt>
                        <dd>{{ $item['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>

    @if ($hasProgress)
        <div class="owwa-pc-scan-card owwa-pc-view-progress owwa-record-kpi-strip">
            <div class="owwa-pc-progress-header">
                <span class="owwa-pc-stat-label">{{ $progress['label'] ?? 'Progress' }}</span>
                <span class="owwa-pc-progress-text">{{ $progress['text'] ?? '' }}</span>
            </div>
            <div class="owwa-pc-progress">
                <div class="owwa-pc-progress-bar" style="width: {{ $progress['percent'] ?? 0 }}%"></div>
            </div>
        </div>
    @endif

    @if ($hasKpis)
        <div class="owwa-pc-scan-card owwa-record-kpi-strip">
            <dl class="owwa-pc-stat-grid">
                @foreach ($kpis as $kpi)
                    <div>
                        <dt class="owwa-pc-stat-label">{{ $kpi['label'] }}</dt>
                        <dd @class(['owwa-pc-stat-value', $kpi['class'] ?? null])>{{ $kpi['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($hasWorkflow)
        <div class="owwa-pc-scan-card owwa-pc-view-workflow">
            <h3 class="owwa-pc-recent-title">{{ $workflowTitle }}</h3>
            <ol class="owwa-pc-phase-stepper" aria-label="{{ $workflowTitle }}">
                @foreach ($workflowSteps as $step)
                    <li @class([
                        'owwa-pc-phase-step',
                        'owwa-pc-phase-step--done' => ($step['state'] ?? '') === 'done',
                        'owwa-pc-phase-step--active' => ($step['state'] ?? '') === 'active',
                    ])>
                        <div class="owwa-pc-phase-step-indicator" aria-hidden="true">
                            <span class="owwa-pc-phase-step-number">{{ $step['step'] }}</span>
                        </div>
                        <div class="owwa-pc-phase-step-body">
                            <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--full">{{ $step['label'] }}</span>
                            <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--short">{{ $step['shortLabel'] ?? $step['label'] }}</span>
                            @if (filled($step['description'] ?? null))
                                <p class="owwa-pc-line-meta">{{ $step['description'] }}</p>
                            @endif
                            @if (filled($step['url'] ?? null))
                                <a href="{{ $step['url'] }}" class="owwa-pc-workflow-link">Open</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if (filled($hint ?? null))
                <p class="owwa-pc-scan-hint">{{ $hint }}</p>
            @endif
        </div>
    @endif
</div>
