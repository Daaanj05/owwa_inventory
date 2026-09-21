@php
    $showingArchived = (bool) ($showingArchived ?? false);
    $archivedCount = (int) ($archivedCount ?? 0);
@endphp

<div class="owwa-setup-archive-view-toggle" role="group" aria-label="Active or archived view">
    <button
        type="button"
        wire:click="$set('showingArchived', false)"
        class="owwa-setup-archive-view-toggle__btn {{ ! $showingArchived ? 'is-active' : '' }}"
        title="Active"
        aria-label="Active"
        aria-pressed="{{ ! $showingArchived ? 'true' : 'false' }}"
    >
        <x-filament::icon icon="heroicon-o-list-bullet" class="h-5 w-5" />
    </button>

    <button
        type="button"
        wire:click="$set('showingArchived', true)"
        class="owwa-setup-archive-view-toggle__btn {{ $showingArchived ? 'is-active' : '' }}"
        title="Archived"
        aria-label="Archived"
        aria-pressed="{{ $showingArchived ? 'true' : 'false' }}"
    >
        <x-filament::icon icon="heroicon-o-archive-box" class="h-5 w-5" />
        @if ($archivedCount > 0)
            <span class="owwa-setup-archive-view-toggle__badge">{{ $archivedCount }}</span>
        @endif
    </button>
</div>
