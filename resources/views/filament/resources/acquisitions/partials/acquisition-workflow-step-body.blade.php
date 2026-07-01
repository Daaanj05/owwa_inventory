@php
    $showLoading = $showLoading ?? false;
@endphp

<div class="owwa-pc-phase-step-indicator" aria-hidden="true">
  @if ($showLoading)
    <span class="owwa-pc-phase-step-number" wire:loading.remove wire:target="mountAction">{{ $step['step'] }}</span>
    <svg
      class="owwa-pc-phase-step-spinner"
      wire:loading
      wire:target="mountAction"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25"></circle>
      <path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"></path>
    </svg>
  @else
    <span class="owwa-pc-phase-step-number">{{ $step['step'] }}</span>
  @endif
</div>
<div class="owwa-pc-phase-step-body">
  <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--full">{{ $step['label'] }}</span>
  <span class="owwa-pc-phase-step-label owwa-pc-phase-step-label--short">{{ $step['shortLabel'] ?? $step['label'] }}</span>
  @if (filled($step['statusLabel'] ?? null))
    <span class="owwa-pc-phase-status-badge">{{ $step['statusLabel'] }}</span>
  @endif
  @if (filled($step['description'] ?? null))
    <p class="owwa-pc-line-meta">{{ $step['description'] }}</p>
  @endif
  @if (filled($step['url'] ?? null))
    <a href="{{ $step['url'] }}" class="owwa-pc-workflow-link" target="_blank" rel="noopener" onclick="event.stopPropagation()">Export & print</a>
  @endif
</div>
