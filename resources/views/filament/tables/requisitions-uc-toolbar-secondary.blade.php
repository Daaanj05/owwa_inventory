@php
    $activeUcTab = $activeUcTab ?? 'received';
    $isReceived = $activeUcTab === 'received';
    $isSent = $activeUcTab === 'sent';
@endphp

<div class="owwa-uc-toolbar-secondary">
    <div class="owwa-uc-secondary-tabs">
        <button
            type="button"
            wire:click="$set('ucTab', 'received')"
            class="fi-tabs-item {{ $isReceived ? 'fi-active' : '' }}"
            role="tab"
            aria-selected="{{ $isReceived ? 'true' : 'false' }}"
        >
            <span class="fi-tabs-item-label">Received (Employee requests)</span>
        </button>

        <button
            type="button"
            wire:click="$set('ucTab', 'sent')"
            class="fi-tabs-item {{ $isSent ? 'fi-active' : '' }}"
            role="tab"
            aria-selected="{{ $isSent ? 'true' : 'false' }}"
        >
            <span class="fi-tabs-item-label">Sent (To Supply Custodian)</span>
        </button>
    </div>

    @if ($isReceived)
        <div class="owwa-uc-scope-filters">
            <select
                wire:model.live="ucOfficeId"
                class="owwa-search-bar owwa-uc-scope-select"
                aria-label="Office"
            >
                <option value="" disabled @selected(blank($ucOfficeId ?? null))>Select office…</option>
                @foreach ($officeOptions ?? [] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="ucDepartmentId"
                class="owwa-search-bar owwa-uc-scope-select"
                aria-label="Department"
            >
                <option value="" disabled @selected(blank($ucDepartmentId ?? null))>Select department…</option>
                @foreach ($departmentOptions ?? [] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

@if ($isReceived && ! ($scopeComplete ?? false))
    <span class="owwa-uc-scope-hint text-sm text-gray-500">Select office and department to view employee requests.</span>
@endif