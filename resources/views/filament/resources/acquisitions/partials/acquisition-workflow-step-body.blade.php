<div class="owwa-pc-phase-step-indicator" aria-hidden="true">
  <span class="owwa-pc-phase-step-number">{{ $step['step'] }}</span>
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
