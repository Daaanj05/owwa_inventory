@php
    /** @var array<string, string> $tabs */
    $tabs = $tabs ?? [];
    $kind = $kind ?? null;
@endphp

<div class="owwa-acquisition-doc-tabs-wrap">
    <div class="owwa-pa-view-tabs owwa-stock-restock-tabs owwa-acquisition-doc-tabs" role="tablist" aria-label="Classification">
        @foreach ($tabs as $value => $label)
            <button
                type="button"
                wire:click="$set('kind', '{{ $value }}')"
                class="owwa-pa-view-tab owwa-acquisition-doc-tab {{ $kind === $value ? 'is-active' : '' }}"
                role="tab"
                aria-selected="{{ $kind === $value ? 'true' : 'false' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
