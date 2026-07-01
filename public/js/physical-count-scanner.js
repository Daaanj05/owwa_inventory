window.owwaQrScanner = function owwaQrScanner({
    componentId,
    elementId = 'physical-count-qr-reader',
    method = 'resolveScan',
    qrboxSize = null,
}) {
    let html5QrCode = null;
    let lastScannedAt = 0;
    const debounceMs = 1500;

    const resolveQrboxSize = () => {
        if (qrboxSize !== null && qrboxSize > 0) {
            return qrboxSize;
        }

        const container = document.getElementById(elementId);

        if (! container) {
            return 250;
        }

        const width = container.clientWidth || container.offsetWidth || 0;
        const height = container.clientHeight || container.offsetHeight || 0;
        const dimension = Math.min(width, height);

        if (dimension <= 0) {
            return 250;
        }

        return Math.min(Math.floor(dimension * 0.7), 280);
    };

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
            const boxSize = resolveQrboxSize();

            try {
                await html5QrCode.start(
                    { facingMode: 'environment' },
                    {
                        fps: 10,
                        qrbox: { width: boxSize, height: boxSize },
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
