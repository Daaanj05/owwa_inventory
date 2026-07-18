{{--
  Reusable leave-page safeguard + busy overlay.
  Props:
    - $busy (bool)
    - $title (string)
    - $message (string)
    - $leaveMessage (string)
    - $busyProperty (optional Livewire bool property name to entangle, e.g. exportBusy)
--}}
@php
    $busy = (bool) ($busy ?? false);
    $title = $title ?? 'Please wait…';
    $message = $message ?? 'This may take a moment. Please stay on this page until it finishes.';
    $leaveMessage = $leaveMessage ?? 'A process is still running. Are you sure you want to leave this page?';
    $busyProperty = $busyProperty ?? null;
@endphp

@once
    <script>
        window.owwaStartExportDownload = function (url, tokenQuery) {
            // Keep tokens cookie-safe and within the server-side length/charset checks.
            const token = ('owwa' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2))
                .replace(/[^A-Za-z0-9_-]/g, '')
                .slice(0, 32);
            let downloadUrl = url;

            try {
                const parsed = new URL(url, window.location.origin);
                parsed.searchParams.set(tokenQuery || 'owwa_download_token', token);
                downloadUrl = parsed.toString();
            } catch (error) {
                const joiner = url.includes('?') ? '&' : '?';
                downloadUrl = url + joiner + encodeURIComponent(tokenQuery || 'owwa_download_token') + '=' + encodeURIComponent(token);
            }

            // Top-level navigation is required for reliable Content-Disposition downloads
            // in Chromium (tab spinner + actual file save). Hidden iframes often fail silently.
            window.location.assign(downloadUrl);

            return token;
        };

        window.owwaBusyGuard = function (config) {
            return {
                busy: Boolean(config.initialBusy),
                title: config.defaultTitle || 'Please wait…',
                message: config.defaultMessage || '',
                leaveMessage: config.leaveMessage || 'A process is still running. Are you sure you want to leave this page?',
                busyProperty: config.busyProperty || null,
                doneCookie: config.doneCookie || 'owwa_export_done',
                tokenQuery: config.tokenQuery || 'owwa_download_token',
                downloadTimer: null,
                cookiePollTimer: null,
                expectedToken: null,
                suppressBusySync: false,
                allowUnload: false,
                init() {
                    window.__owwaBusyGuardInstance = this;
                    window.addEventListener('beforeunload', (event) => this.onBeforeUnload(event));
                    document.addEventListener('click', (event) => this.onDocumentClick(event), true);

                    this.$watch('busy', (value) => {
                        document.body.classList.toggle('owwa-busy-active', Boolean(value));
                    });

                    if (this.busy) {
                        document.body.classList.add('owwa-busy-active');
                    }

                    this.$el.addEventListener('livewire:navigated', () => this.syncFromLivewire());
                    queueMicrotask(() => this.syncFromLivewire());
                    setInterval(() => this.syncFromLivewire(), 1000);
                },
                livewireComponent() {
                    if (! window.Livewire || ! this.$el) {
                        return null;
                    }

                    const root = this.$el.closest('[wire\\:id]');
                    const id = root ? root.getAttribute('wire:id') : null;

                    return id ? window.Livewire.find(id) : null;
                },
                syncFromLivewire() {
                    if (this.suppressBusySync) {
                        return;
                    }

                    const component = this.livewireComponent();
                    if (! component) {
                        return;
                    }

                    if (this.busyProperty && typeof component.get === 'function') {
                        this.busy = Boolean(component.get(this.busyProperty));
                    }

                    const loading = typeof component.get === 'function' ? Boolean(component.get('loading')) : false;
                    const processingRunId = typeof component.get === 'function' ? component.get('processingRunId') : null;

                    if (! this.busyProperty && (loading || processingRunId)) {
                        this.busy = true;
                        this.title = config.defaultTitle || this.title;
                        this.message = config.defaultMessage || this.message;
                    } else if (! this.busyProperty && ! loading && ! processingRunId && ! this.downloadTimer) {
                        this.busy = false;
                    }
                },
                readCookie(name) {
                    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[$()*+.?[\\\]^{|}]/g, '\\$&') + '=([^;]*)'));
                    return match ? decodeURIComponent(match[1]) : null;
                },
                clearCookie(name) {
                    document.cookie = name + '=; Max-Age=0; path=/; SameSite=Lax';
                },
                start(detail = {}) {
                    this.suppressBusySync = false;
                    this.allowUnload = false;
                    this.title = detail.title || config.defaultTitle || 'Please wait…';
                    this.message = detail.message || config.defaultMessage || '';
                    this.busy = true;

                    if (detail.url) {
                        this.beginDownload(detail.url, detail.autoClearMs || 120000);
                        return;
                    }

                    // Redirect-driven downloads: poll the done-cookie and fail-safe clear.
                    if (detail.token) {
                        this.expectedToken = detail.token;
                    }

                    this.watchDownloadCompletion(detail.autoClearMs || 120000);
                },
                watchDownloadCompletion(autoClearMs) {
                    this.clearCookie(this.doneCookie);

                    if (this.cookiePollTimer) {
                        clearInterval(this.cookiePollTimer);
                    }

                    if (this.expectedToken) {
                        this.cookiePollTimer = setInterval(() => {
                            const done = this.readCookie(this.doneCookie);
                            if (done && done === this.expectedToken) {
                                this.end();
                            }
                        }, 250);
                    }

                    if (this.downloadTimer) {
                        clearTimeout(this.downloadTimer);
                    }

                    this.downloadTimer = setTimeout(() => {
                        this.end();
                    }, Math.min(Number(autoClearMs) || 120000, 120000));
                },
                end() {
                    this.busy = false;
                    this.allowUnload = false;
                    this.suppressBusySync = true;
                    this.title = config.defaultTitle || 'Please wait…';
                    this.message = config.defaultMessage || '';
                    this.expectedToken = null;

                    if (this.downloadTimer) {
                        clearTimeout(this.downloadTimer);
                        this.downloadTimer = null;
                    }

                    if (this.cookiePollTimer) {
                        clearInterval(this.cookiePollTimer);
                        this.cookiePollTimer = null;
                    }

                    this.clearCookie(this.doneCookie);

                    const component = this.livewireComponent();
                    if (! component || this.busyProperty !== 'exportBusy') {
                        return;
                    }

                    if (typeof component.set === 'function') {
                        component.set('exportBusy', false);
                    } else if (typeof component.call === 'function') {
                        component.call('clearExportBusy');
                    }
                },
                beginDownload(url, autoClearMs) {
                    this.clearCookie(this.doneCookie);

                    if (this.cookiePollTimer) {
                        clearInterval(this.cookiePollTimer);
                    }

                    this.cookiePollTimer = setInterval(() => {
                        const done = this.readCookie(this.doneCookie);
                        if (done && done === this.expectedToken) {
                            this.end();
                        }
                    }, 250);

                    // Allow the download navigation; leave-guard still blocks sidebar/clicks.
                    this.allowUnload = true;
                    this.expectedToken = window.owwaStartExportDownload(url, this.tokenQuery);
                    // Attachment downloads usually do not unload the page; re-arm leave-guard.
                    setTimeout(() => {
                        this.allowUnload = false;
                    }, 1500);

                    if (this.downloadTimer) {
                        clearTimeout(this.downloadTimer);
                    }

                    // Fail-safe: never leave the overlay up longer than 2 minutes by default.
                    this.downloadTimer = setTimeout(() => {
                        this.end();
                    }, Math.min(Number(autoClearMs) || 120000, 120000));
                },
                onBeforeUnload(event) {
                    if (! this.busy || this.allowUnload) {
                        return;
                    }

                    event.preventDefault();
                    event.returnValue = this.leaveMessage;

                    return this.leaveMessage;
                },
                onDocumentClick(event) {
                    if (! this.busy) {
                        return;
                    }

                    const anchor = event.target.closest('a[href]');
                    if (! anchor) {
                        return;
                    }

                    const href = anchor.getAttribute('href') || '';
                    if (
                        href === '' ||
                        href.startsWith('#') ||
                        href.startsWith('javascript:') ||
                        anchor.hasAttribute('download') ||
                        (anchor.target && anchor.target !== '_self')
                    ) {
                        return;
                    }

                    if (! window.confirm(this.leaveMessage)) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                },
            };
        };

        window.addEventListener('owwa-busy-start', (event) => {
            const detail = event.detail || {};
            const instance = window.__owwaBusyGuardInstance;

            if (instance && typeof instance.start === 'function') {
                instance.start(detail);
                return;
            }

            if (! detail.url) {
                return;
            }

            window.owwaStartExportDownload(
                detail.url,
                @js(\App\Support\OwwaExportDownloadCookie::TOKEN_QUERY),
            );
        });
    </script>
@endonce

<div
    x-data="owwaBusyGuard({
        initialBusy: @js($busy),
        defaultTitle: @js($title),
        defaultMessage: @js($message),
        leaveMessage: @js($leaveMessage),
        busyProperty: @js($busyProperty),
        doneCookie: @js(\App\Support\OwwaExportDownloadCookie::DONE_COOKIE),
        tokenQuery: @js(\App\Support\OwwaExportDownloadCookie::TOKEN_QUERY),
    })"
    x-init="init()"
    x-on:owwa-busy-end.window="end()"
>
    <div
        class="owwa-busy-overlay"
        x-show="busy"
        x-cloak
        x-transition.opacity
        role="alertdialog"
        aria-modal="true"
        aria-live="assertive"
        :aria-hidden="busy ? 'false' : 'true'"
    >
        <div class="owwa-busy-dialog">
            <div class="owwa-busy-spinner" aria-hidden="true"></div>
            <p class="owwa-busy-title" x-text="title"></p>
            <p class="owwa-busy-message" x-text="message"></p>
            <p class="owwa-busy-hint">Please don’t close or leave this page until it finishes.</p>
            <button
                type="button"
                class="owwa-busy-dismiss"
                x-on:click="end()"
            >
                Dismiss
            </button>
        </div>
    </div>
</div>
