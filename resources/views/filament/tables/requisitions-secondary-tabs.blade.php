@php
    $isReceived = ($activeUcTab ?? 'received') === 'received';
    $isSent = ($activeUcTab ?? '') === 'sent';
@endphp

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

