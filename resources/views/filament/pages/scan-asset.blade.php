<x-filament-panels::page>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="{{ asset('js/physical-count-scanner.js') }}"></script>

    <div class="owwa-physical-count-scan-page owwa-pc-scan-container">
        <div class="owwa-pc-scan-card owwa-scan-asset-card">
            <p class="owwa-scan-asset-intro">Point your camera at the property QR tag.</p>

            <div
                wire:ignore
                class="owwa-scan-asset-camera-wrap"
                x-data="owwaQrScanner({ componentId: @js($this->getId()), elementId: 'asset-lookup-qr-reader', qrboxSize: 200 })"
                x-init="init()"
                x-on:destroy="destroy()"
            >
                <div x-show="cameraUnavailable" x-cloak class="owwa-pc-camera-notice">
                    Camera access is unavailable (HTTPS required on mobile). Enter the property number below.
                </div>

                <div class="owwa-pc-camera-box owwa-scan-asset-camera-box">
                    <div id="asset-lookup-qr-reader" class="owwa-pc-qr-reader"></div>
                    <div class="owwa-scan-asset-frame" aria-hidden="true"></div>
                </div>
            </div>

            <div class="owwa-scan-asset-lookup">
                <label class="owwa-scan-asset-lookup-label" for="scan-asset-property-number">Property number</label>
                <form wire:submit="submitManualCode" class="owwa-pc-manual-form owwa-scan-asset-manual-form">
                    <input
                        id="scan-asset-property-number"
                        type="text"
                        wire:model="manualCode"
                        placeholder="Type the property number"
                        class="fi-input owwa-pc-manual-input"
                        autocomplete="off"
                    />
                    <button type="submit" class="fi-btn fi-btn-size-md fi-color fi-color-primary owwa-scan-asset-lookup-btn">
                        Look up
                    </button>
                </form>
            </div>
        </div>

        @if ($duplicateUnitOptions !== [])
            <div class="owwa-pc-scan-card owwa-scan-asset-result">
                <div class="owwa-scan-asset-result-header">
                    <h2 class="owwa-scan-asset-result-title">Several units share this number</h2>
                    <button
                        type="button"
                        wire:click="clearResolvedAsset"
                        class="fi-btn fi-btn-size-sm fi-color fi-color-gray owwa-scan-asset-clear-btn"
                    >
                        Clear
                    </button>
                </div>

                <p class="owwa-scan-asset-result-hint">
                    Pick the unit that matches the physical item (check the item, office, and status).
                </p>

                <div class="owwa-scan-asset-actions">
                    @foreach ($duplicateUnitOptions as $unitId => $label)
                        <button
                            type="button"
                            wire:click="selectDuplicateUnit({{ $unitId }})"
                            class="fi-btn fi-btn-size-md fi-color fi-color-gray"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($resolvedUnitId)
            <div class="owwa-pc-scan-card owwa-scan-asset-result" wire:key="scan-asset-result-{{ $resolvedUnitId }}">
                <div class="owwa-scan-asset-result-header">
                    <h2 class="owwa-scan-asset-result-title">Scanned asset</h2>
                    <button
                        type="button"
                        wire:click="clearResolvedAsset"
                        class="fi-btn fi-btn-size-sm fi-color fi-color-gray owwa-scan-asset-clear-btn"
                    >
                        Clear
                    </button>
                </div>

                <dl class="owwa-scan-asset-result-grid">
                    <div>
                        <dt>Inventory item no.</dt>
                        <dd>{{ $resolvedPropertyNumber }}</dd>
                    </div>
                    <div>
                        <dt>Item</dt>
                        <dd>{{ $resolvedItemName }}</dd>
                    </div>
                    <div>
                        <dt>Description</dt>
                        <dd>{{ $resolvedDetails['description'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Unit cost</dt>
                        <dd>{{ $resolvedDetails['unit_cost'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Source</dt>
                        <dd>{{ $resolvedDetails['source'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Office</dt>
                        <dd>{{ $resolvedOfficeName }}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd class="owwa-scan-asset-status">{{ $resolvedStatus }}</dd>
                    </div>
                    <div>
                        <dt>Holder · ICS/PAR</dt>
                        <dd>{{ $resolvedDetails['holder'] ?? '—' }}</dd>
                    </div>
                </dl>

                <p class="owwa-scan-asset-result-hint">
                    @if ($resolvedIsClaimed)
                        This unit is linked to a disposal or incident report draft. Only the QR label reprint is available.
                    @else
                        Choose an action. Finish the remaining details on desktop, then confirm when ready.
                    @endif
                </p>

                <div class="owwa-scan-asset-actions">
                    @unless ($resolvedIsClaimed)
                        <button
                            type="button"
                            wire:click="startDisposal"
                            class="owwa-scan-asset-action-card owwa-scan-asset-action-card--danger"
                        >
                            <span class="owwa-scan-asset-action-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="22" height="22">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </span>
                            <span class="owwa-scan-asset-action-copy">
                                <span class="owwa-scan-asset-action-title">Disposal</span>
                                <span class="owwa-scan-asset-action-hint">Open a disposal record for this property tag.</span>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="startTransfer"
                            class="owwa-scan-asset-action-card owwa-scan-asset-action-card--primary"
                        >
                            <span class="owwa-scan-asset-action-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="22" height="22">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                </svg>
                            </span>
                            <span class="owwa-scan-asset-action-copy">
                                <span class="owwa-scan-asset-action-title">Transfer</span>
                                <span class="owwa-scan-asset-action-hint">Move this unit to another office.</span>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="startIncident"
                            class="fi-btn fi-btn-size-md fi-color fi-color-warning"
                        >
                            Incident report
                        </button>
                    @endunless
                    {{ $this->reprintQrLabelAction }}
                </div>

                @if ($resolvedPublicUrl)
                    <p class="owwa-scan-asset-public-link">
                        <a href="{{ $resolvedPublicUrl }}" target="_blank" rel="noopener noreferrer">
                            View public asset card
                        </a>
                    </p>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
