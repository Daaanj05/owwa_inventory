@php
    $labels = $labels ?? [];
    $counts = $counts ?? [];
    $activeTab = is_string($activeTab ?? null) ? $activeTab : null;
@endphp

<div class="owwa-signatory-form-tabs" role="tablist" aria-label="Signatory forms">
    @foreach ($labels as $key => $label)
        @php
            $isActive = $activeTab === $key;
            $count = (int) ($counts[$key] ?? 0);
        @endphp
        <button
            type="button"
            wire:click="$set('activeTab', '{{ $key }}')"
            class="fi-tabs-item {{ $isActive ? 'fi-active' : '' }}"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
        >
            <span class="fi-tabs-item-label">{{ $label }}</span>
            @if ($count > 0)
                <span class="fi-badge">{{ $count }}</span>
            @endif
        </button>
    @endforeach
</div>
