<div
    wire:poll.4s="refreshProcessingRun"
    @ai-procurement-busy-refresh.window="$wire.refreshProcessingRun()"
>
    @if ($this->shouldShowChip)
        <div
            class="owwa-busy-chip"
            role="status"
            aria-live="polite"
        >
            <div class="owwa-busy-chip-spinner" aria-hidden="true"></div>
            <p class="owwa-busy-chip-title">Generating recommendation…</p>
            <a
                href="{{ $this->analyticsUrl }}"
                class="owwa-busy-chip-expand"
                wire:navigate
            >
                Expand
            </a>
        </div>
    @endif
</div>
