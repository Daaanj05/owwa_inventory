<x-filament-panels::page>
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

            <div class="owwa-scan-asset-divider" role="separator">
                <span>or enter property number</span>
            </div>

            <form wire:submit="submitManualCode" class="owwa-pc-manual-form owwa-scan-asset-manual-form">
                <input
                    type="text"
                    wire:model="manualCode"
                    placeholder="Property number"
                    class="fi-input owwa-pc-manual-input"
                    autocomplete="off"
                />
                <button type="submit" class="fi-btn fi-btn-size-md fi-color fi-color-primary owwa-scan-asset-lookup-btn">
                    Look up
                </button>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="{{ asset('js/physical-count-scanner.js') }}"></script>
</x-filament-panels::page>
