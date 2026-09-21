@php
    $mode = $mode ?? 'archive';
    $showingArchived = (bool) ($showingArchived ?? false);
    $showingOpeningBalances = (bool) ($showingOpeningBalances ?? false);
    $badgeCount = (int) ($badgeCount ?? 0);
    $isOpeningMode = $mode === 'opening';
    $leftActive = $isOpeningMode ? ! $showingOpeningBalances : ! $showingArchived;
    $rightActive = $isOpeningMode ? $showingOpeningBalances : $showingArchived;
    $leftLabel = $isOpeningMode ? 'PO/IAR received' : 'Active';
    $rightLabel = $isOpeningMode ? 'Opening balances' : 'Archived';
    $leftIcon = $isOpeningMode ? 'heroicon-o-inbox-arrow-down' : 'heroicon-o-list-bullet';
    $rightIcon = $isOpeningMode ? 'heroicon-o-cube' : 'heroicon-o-archive-box';
@endphp

<div class="owwa-setup-archive-view-toggle owwa-acquisition-list-view-toggle" role="group" aria-label="{{ $isOpeningMode ? 'Received or opening balances view' : 'Active or archived view' }}">
    <button
        type="button"
        @if ($isOpeningMode)
            wire:click="$set('showingOpeningBalances', false)"
        @else
            wire:click="$set('showingArchived', false)"
        @endif
        class="owwa-setup-archive-view-toggle__btn {{ $leftActive ? 'is-active' : '' }}"
        title="{{ $leftLabel }}"
        aria-label="{{ $leftLabel }}"
        aria-pressed="{{ $leftActive ? 'true' : 'false' }}"
    >
        <x-filament::icon icon="{{ $leftIcon }}" class="h-5 w-5" />
    </button>

    <button
        type="button"
        @if ($isOpeningMode)
            wire:click="$set('showingOpeningBalances', true)"
        @else
            wire:click="$set('showingArchived', true)"
        @endif
        class="owwa-setup-archive-view-toggle__btn {{ $rightActive ? 'is-active' : '' }}"
        title="{{ $rightLabel }}"
        aria-label="{{ $rightLabel }}"
        aria-pressed="{{ $rightActive ? 'true' : 'false' }}"
    >
        <x-filament::icon icon="{{ $rightIcon }}" class="h-5 w-5" />
        @if ($badgeCount > 0)
            <span class="owwa-setup-archive-view-toggle__badge">{{ $badgeCount }}</span>
        @endif
    </button>
</div>
