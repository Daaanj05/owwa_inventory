window.owwaQrScanner = function owwaQrScanner({
    componentId,
    elementId = 'physical-count-qr-reader',
    method = 'resolveScan',
    qrboxSize = null,
    aspectRatio = 1,
    countedPropertyNumbers = [],
    normalizeMethod = null,
}) {
    let html5QrCode = null;
    let lastScannedAt = 0;
    let initializing = false;
    let started = false;
    const debounceMs = 1500;
    const blockedPropertyNumbers = new Set(
        (countedPropertyNumbers ?? []).map((value) => String(value).trim()).filter(Boolean),
    );

    const waitForHtml5Qrcode = async () => {
        if (typeof Html5Qrcode !== 'undefined') {
            return;
        }

        const deadline = Date.now() + 3000;

        while (typeof Html5Qrcode === 'undefined' && Date.now() < deadline) {
            if (document.readyState === 'complete') {
                await new Promise((resolve) => setTimeout(resolve, 50));
            } else {
                await new Promise((resolve) => {
                    window.addEventListener('load', resolve, { once: true });
                });
            }
        }
    };

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

    const normalizePropertyNumber = (decodedText) => {
        const raw = String(decodedText ?? '').trim();

        if (raw === '') {
            return '';
        }

        if (typeof normalizeMethod === 'function') {
            return normalizeMethod(raw);
        }

        if (raw.includes('/assets/')) {
            try {
                const url = new URL(raw, window.location.origin);
                const segments = url.pathname.split('/').filter(Boolean);
                const assetsIndex = segments.indexOf('assets');

                if (assetsIndex !== -1 && segments[assetsIndex + 1]) {
                    return decodeURIComponent(segments[assetsIndex + 1]);
                }
            } catch (error) {
                // Fall through to raw parsing.
            }
        }

        if (raw.startsWith('OWWA:PN:')) {
            return raw.slice('OWWA:PN:'.length).trim();
        }

        return raw;
    };

    const addBlockedPropertyNumber = (propertyNumber) => {
        const normalized = String(propertyNumber ?? '').trim();

        if (normalized !== '') {
            blockedPropertyNumbers.add(normalized);
        }
    };

    const isBlockedPropertyNumber = (decodedText) => {
        const propertyNumber = normalizePropertyNumber(decodedText);

        return propertyNumber !== '' && blockedPropertyNumbers.has(propertyNumber);
    };

    const handleScan = (decodedText) => {
        if (isBlockedPropertyNumber(decodedText)) {
            return;
        }

        const now = Date.now();

        if (now - lastScannedAt < debounceMs) {
            return;
        }

        lastScannedAt = now;
        window.Livewire.find(componentId)?.call(method, decodedText);
    };

    const startScanner = async () => {
        const boxSize = resolveQrboxSize();

        await html5QrCode.start(
            { facingMode: 'environment' },
            {
                fps: 10,
                qrbox: { width: boxSize, height: boxSize },
                aspectRatio,
            },
            handleScan,
            () => {},
        );

        started = true;
    };

    const cleanupScanner = async () => {
        if (! html5QrCode) {
            return;
        }

        if (html5QrCode.isScanning) {
            try {
                await html5QrCode.stop();
            } catch (error) {
                console.error('OWWA QR scanner failed to stop', error);
            }
        }

        try {
            await html5QrCode.clear();
        } catch (error) {
            console.error('OWWA QR scanner failed to clear', error);
        }

        html5QrCode = null;
        started = false;
        initializing = false;
    };

    return {
        cameraUnavailable: false,

        async init() {
            if (initializing || started) {
                return;
            }

            initializing = true;

            if (! window.Livewire) {
                this.cameraUnavailable = true;
                initializing = false;

                return;
            }

            if (! navigator.mediaDevices?.getUserMedia) {
                this.cameraUnavailable = true;
                initializing = false;

                return;
            }

            await waitForHtml5Qrcode();

            if (typeof Html5Qrcode === 'undefined') {
                console.error('Html5Qrcode library is not loaded.');
                this.cameraUnavailable = true;
                initializing = false;

                return;
            }

            const container = document.getElementById(elementId);

            if (! container) {
                this.cameraUnavailable = true;
                initializing = false;

                return;
            }

            html5QrCode = new Html5Qrcode(elementId);

            try {
                await startScanner();
            } catch (error) {
                console.error('OWWA QR scanner failed to start', error);
                this.cameraUnavailable = true;
                await cleanupScanner();
            } finally {
                initializing = false;
            }
        },

        async stop() {
            await cleanupScanner();
        },

        registerCountedPropertyNumber(propertyNumber) {
            addBlockedPropertyNumber(propertyNumber);
        },

        handleScanProcessed(event) {
            const detail = event?.detail ?? {};
            const propertyNumber = detail.propertyNumber ?? null;
            const outcome = detail.outcome ?? null;

            if (propertyNumber) {
                addBlockedPropertyNumber(propertyNumber);
            }

            if (outcome === 'duplicate') {
                this.pauseBriefly();
            }
        },

        async pauseBriefly() {
            if (! html5QrCode?.isScanning) {
                return;
            }

            try {
                await cleanupScanner();
                await new Promise((resolve) => setTimeout(resolve, 800));

                const container = document.getElementById(elementId);

                if (! container) {
                    return;
                }

                html5QrCode = new Html5Qrcode(elementId);
                await startScanner();
            } catch (error) {
                console.error('OWWA QR scanner failed to pause', error);
            }
        },

        destroy() {
            this.stop();
        },
    };
};

window.physicalCountScanner = function physicalCountScanner({
    componentId,
    countedPropertyNumbers = [],
}) {
    return window.owwaQrScanner({
        componentId,
        elementId: 'physical-count-qr-reader',
        method: 'resolveScan',
        aspectRatio: 1,
        countedPropertyNumbers,
    });
};
