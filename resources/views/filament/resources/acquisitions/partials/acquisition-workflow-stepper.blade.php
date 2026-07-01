@php
    $clickable = $clickable ?? false;
    $compact = $compact ?? false;
    $recordKey = $recordKey ?? null;
    $hint = $hint ?? null;
    $title = $title ?? 'Workflow';
@endphp

<div @class([
    'owwa-pc-scan-card owwa-pc-view-workflow',
    'owwa-acquisition-workflow-stepper--compact' => $compact,
    'owwa-pc-view-workflow--interactive' => $clickable,
])>
  @if ($clickable)
    <div
      class="owwa-workflow-loading-overlay"
      wire:loading.delay.shortest
      wire:target="mountAction"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div class="owwa-workflow-loading-card">
        <svg class="owwa-pr-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle class="owwa-pr-spinner-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
          <path class="owwa-pr-spinner-head" d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
        </svg>
        <div>
          <p class="owwa-pr-loading-title">Opening form…</p>
          <p class="owwa-pr-loading-sub">Loading purchase paperwork details.</p>
        </div>
      </div>
    </div>
  @endif

  @if (! $compact)
    <h3 class="owwa-pc-recent-title">{{ $title }}</h3>
  @endif
  <ol class="owwa-pc-phase-stepper" aria-label="Acquisition workflow">
    @foreach ($workflowSteps as $step)
      @php
          $isLocked = ($step['state'] ?? '') === 'pending';
          $isNavigable = $clickable
              && filled($step['actionKey'] ?? null)
              && filled($recordKey)
              && ($step['navigable'] ?? false);
      @endphp
      <li @class([
          'owwa-pc-phase-step',
          'owwa-pc-phase-step--done' => ($step['state'] ?? '') === 'done',
          'owwa-pc-phase-step--active' => ($step['state'] ?? '') === 'active',
          'owwa-pc-phase-step--locked' => $isLocked,
          'owwa-pc-phase-step--clickable' => $isNavigable,
      ])>
        @if ($isNavigable)
          <button
            type="button"
            class="owwa-pc-phase-step-button"
            wire:click="mountAction('{{ $step['actionKey'] }}')"
            wire:loading.attr="disabled"
            wire:loading.class="owwa-pc-phase-step-button--loading"
            wire:target="mountAction"
            aria-label="Open {{ $step['label'] }}"
          >
            @include('filament.resources.acquisitions.partials.acquisition-workflow-step-body', ['step' => $step, 'showLoading' => true])
          </button>
        @else
          <div class="owwa-pc-phase-step-button owwa-pc-phase-step-button--static">
            @include('filament.resources.acquisitions.partials.acquisition-workflow-step-body', ['step' => $step, 'showLoading' => false])
          </div>
        @endif
      </li>
    @endforeach
  </ol>
  @if (filled($hint))
    <p class="owwa-pc-scan-hint">{{ $hint }}</p>
  @endif
</div>
