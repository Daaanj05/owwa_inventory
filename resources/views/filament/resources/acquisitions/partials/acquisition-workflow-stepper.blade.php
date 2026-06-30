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
])>
  @if (! $compact)
    <h3 class="owwa-pc-recent-title">{{ $title }}</h3>
  @endif
  <ol class="owwa-pc-phase-stepper" aria-label="Acquisition workflow">
    @foreach ($workflowSteps as $step)
      <li @class([
          'owwa-pc-phase-step',
          'owwa-pc-phase-step--done' => ($step['state'] ?? '') === 'done',
          'owwa-pc-phase-step--active' => ($step['state'] ?? '') === 'active',
          'owwa-pc-phase-step--clickable' => $clickable && filled($step['actionKey'] ?? null) && filled($recordKey),
      ])>
        @if ($clickable && filled($step['actionKey'] ?? null) && filled($recordKey))
          <button
            type="button"
            class="owwa-pc-phase-step-button"
            wire:click="mountTableAction('{{ $step['actionKey'] }}', '{{ $recordKey }}')"
          >
            @include('filament.resources.acquisitions.partials.acquisition-workflow-step-body', ['step' => $step])
          </button>
        @else
          <div class="owwa-pc-phase-step-button owwa-pc-phase-step-button--static">
            @include('filament.resources.acquisitions.partials.acquisition-workflow-step-body', ['step' => $step])
          </div>
        @endif
      </li>
    @endforeach
  </ol>
  @if (filled($hint))
    <p class="owwa-pc-scan-hint">{{ $hint }}</p>
  @endif
</div>
