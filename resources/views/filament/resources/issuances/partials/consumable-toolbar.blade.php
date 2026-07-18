@php
    $issuanceView = $issuanceView ?? 'all';
    $isArchived = ($activeTab ?? 'active') === 'archived';
    $todayBadge = (int) ($todayBadge ?? 0);
@endphp

<div
    class="owwa-pa-view-tabs owwa-issuances-view-tabs owwa-stock-restock-tabs {{ $isArchived ? 'is-disabled' : '' }}"
    role="tablist"
    aria-label="Issuance view"
>
    <button
        type="button"
        role="tab"
        wire:click="setIssuanceView('all')"
        class="owwa-pa-view-tab {{ $issuanceView === 'all' && ! $isArchived ? 'is-active' : '' }}"
        aria-selected="{{ $issuanceView === 'all' && ! $isArchived ? 'true' : 'false' }}"
        @disabled($isArchived)
    >
        All issuances
    </button>
    <button
        type="button"
        role="tab"
        wire:click="setIssuanceView('today_rsmi')"
        class="owwa-pa-view-tab {{ $issuanceView === 'today_rsmi' && ! $isArchived ? 'is-active' : '' }}"
        aria-selected="{{ $issuanceView === 'today_rsmi' && ! $isArchived ? 'true' : 'false' }}"
        @disabled($isArchived)
    >
        Today's RSMI
        @if ($todayBadge > 0)
            <span class="owwa-issuances-view-badge">{{ $todayBadge }}</span>
        @endif
    </button>
</div>
