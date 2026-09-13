<div
    wire:poll.4s="refreshProcessingRun"
    @ai-procurement-busy-refresh.window="$wire.refreshProcessingRun()"
    x-data="{
        onAnalytics: false,
        syncPath() {
            this.onAnalytics = window.location.pathname.includes('procurement-analytics');
        },
    }"
    x-init="syncPath()"
    x-on:livewire:navigated.window="syncPath()"
>
    @if ($this->shouldShowChip)
        <div
            class="owwa-busy-chip"
            role="status"
            aria-live="polite"
            x-show="! onAnalytics"
            x-cloak
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
