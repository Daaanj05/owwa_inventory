{{-- Search-toolbar actions: New/Create first, then Export (with icon). --}}
@php
    $showCreate = (bool) ($showCreate ?? false);
    $createAction = $createAction ?? null;
    $createLabel = $createLabel ?? 'New';
    $exportAction = $exportAction ?? 'exportProcurementReport';
@endphp
<div class="owwa-acquisition-search-row-actions">
    @if ($showCreate && filled($createAction))
        <button
            type="button"
            class="fi-btn fi-color-primary fi-bg-color-400 fi-text-color-950 fi-btn-color-primary fi-size-md fi-btn-solid owwa-acquisition-search-new-btn"
            wire:click="mountAction(@js($createAction))"
        >
            <span class="fi-btn-label">{{ $createLabel }}</span>
        </button>
    @endif

    <button
        type="button"
        class="fi-btn fi-btn-color-gray fi-size-md fi-btn-outlined owwa-acquisition-search-export-btn"
        wire:click="mountAction(@js($exportAction))"
    >
        <svg class="fi-icon fi-size-md" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
        </svg>
        <span class="fi-btn-label">Export Report</span>
    </button>
</div>
