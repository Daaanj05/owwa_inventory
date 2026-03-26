@php
    $record = $getRecord();
    $statusMeta = match($record->status) {
        'draft'        => ['bg' => '#f1f5f9', 'text' => '#475569', 'dot' => '#94a3b8', 'label' => 'Draft'],
        'for_approval' => ['bg' => '#fef3c7', 'text' => '#92400e', 'dot' => '#f59e0b', 'label' => 'For Approval'],
        'approved'     => ['bg' => '#dcfce7', 'text' => '#166534', 'dot' => '#22c55e', 'label' => 'Approved'],
        'archived'     => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'dot' => '#9ca3af', 'label' => 'Archived'],
        default        => ['bg' => '#f1f5f9', 'text' => '#475569', 'dot' => '#94a3b8', 'label' => ucfirst($record->status)],
    };
    $summary = $record->summary ?? '';
    $summary = preg_replace('/[#*_`~>]+/', '', $summary);
    $summary = trim(preg_replace('/\s+/', ' ', $summary));
    $itemCount = $record->items()->count();
@endphp

{{-- Run banner --}}
<div class="owwa-run-banner">
    <div class="owwa-run-banner-left">
        <div class="owwa-run-banner-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
            </svg>
        </div>
        <div>
            <div class="owwa-run-banner-title">AI Procurement Analysis</div>
            <div class="owwa-run-banner-meta">
                Generated {{ $record->ran_at?->format('M d, Y \a\t g:i A') ?? '—' }}
                @if($record->creator)
                    &nbsp;·&nbsp; by {{ $record->creator->name }}
                @endif
                &nbsp;·&nbsp;
                <span style="font-weight:600; color:rgba(255,255,255,0.8);">{{ $itemCount }} {{ Str::plural('item', $itemCount) }} flagged</span>
            </div>
        </div>
    </div>
    <span class="owwa-run-status-badge" style="background:{{ $statusMeta['bg'] }}; color:{{ $statusMeta['text'] }};">
        <span class="owwa-run-status-dot" style="background:{{ $statusMeta['dot'] }};"></span>
        {{ $statusMeta['label'] }}
    </span>
</div>

@if($summary)
    {{-- Summary card --}}
    <div class="owwa-run-summary" style="margin-top: 1rem;">
        <p class="owwa-run-summary-label">AI Summary</p>
        <p class="owwa-run-summary-text">{{ $summary }}</p>
    </div>
@endif
