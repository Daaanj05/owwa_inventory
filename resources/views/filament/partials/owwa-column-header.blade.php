@php
    $labelText = is_array($label) ? ($label['label'] ?? '') : $label;
    $tooltipText = is_array($label) ? ($label['tooltip'] ?? null) : ($tooltip ?? null);
@endphp

<span class="owwa-th-has-tooltip">
    {{ $labelText }}
    @if (filled($tooltipText))
        <button
            type="button"
            class="owwa-th-tooltip-icon"
            aria-label="{{ $tooltipText }}"
            @mouseenter="$dispatch('owwa-header-tooltip-show', { text: @js($tooltipText), target: $event.currentTarget })"
            @mouseleave="$dispatch('owwa-header-tooltip-hide')"
        >
            <span aria-hidden="true">?</span>
        </button>
    @endif
</span>
