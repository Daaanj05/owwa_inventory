window.owwaQrScanner = function owwaQrScanner({
    componentId,
    elementId = 'physical-count-qr-reader',
    method = 'resolveScan',
    qrboxSize = 250,
}) {
    let html5QrCode = null;
    let lastScannedAt = 0;
    const debounceMs = 1500;

    return {
        cameraUnavailable: false,

        async init() {
            if (!window.Livewire) {
                this.cameraUnavailable = true;

                return;
            }

            if (!navigator.mediaDevices?.getUserMedia) {
                this.cameraUnavailable = true;

                return;
            }

            if (typeof Html5Qrcode === 'undefined') {
                await new Promise((resolve) => {
                    window.addEventListener('load', resolve, { once: true });
                });
            }

            if (typeof Html5Qrcode === 'undefined') {
                console.error('Html5Qrcode library is not loaded.');
                this.cameraUnavailable = true;

                return;
            }

            html5QrCode = new Html5Qrcode(elementId);

            try {
                await html5QrCode.start(
                    { facingMode: 'environment' },
                    {
                        fps: 10,
                        qrbox: { width: qrboxSize, height: qrboxSize },
                        aspectRatio: 1,
                    },
                    (decodedText) => {
                        const now = Date.now();
                        if (now - lastScannedAt < debounceMs) {
                            return;
                        }

                        lastScannedAt = now;
                        window.Livewire.find(componentId)?.call(method, decodedText);
                    },
                    () => {},
                );
            } catch (error) {
                console.error('OWWA QR scanner failed to start', error);
                this.cameraUnavailable = true;
            }

            window.addEventListener('beforeunload', () => {
                this.stop();
            });
        },

        async stop() {
            if (html5QrCode?.isScanning) {
                try {
                    await html5QrCode.stop();
                } catch (error) {
                    console.error('OWWA QR scanner failed to stop', error);
                }
            }
        },
    };
};

window.physicalCountScanner = function physicalCountScanner({ componentId }) {
    return window.owwaQrScanner({
        componentId,
        elementId: 'physical-count-qr-reader',
        method: 'resolveScan',
    });
};
