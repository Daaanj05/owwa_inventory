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
                            class="fi-btn fi-btn-size-md fi-color fi-color-danger"
                        >
                            Disposal
                        </button>
                        <button
                            type="button"
                            wire:click="startIncident"
                            class="fi-btn fi-btn-size-md fi-color fi-color-warning"
                        >
                            Incident report
                        </button>
                        <button
                            type="button"
                            wire:click="startTransfer"
                            class="fi-btn fi-btn-size-md fi-color fi-color-primary"
                        >
                            Transfer
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
