@php
    $showCreate = (bool) ($showCreate ?? false);
@endphp

@if ($showCreate)
    <div class="owwa-incident-search-row-actions">
        <button
            type="button"
            class="fi-btn fi-color-primary fi-bg-color-400 fi-text-color-950 fi-btn-color-primary fi-size-md fi-btn-solid"
            wire:click="mountAction('create', {}, { schemaComponent: 'content' })"
        >
            <span class="fi-btn-label">New incident report</span>
        </button>
    </div>
@endif
