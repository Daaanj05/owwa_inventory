{{-- On Livewire HTTP 419: show a banner. Never injected on guest/login pages. --}}
@php
    $recoverUrl = route('session.recover');
@endphp
<script>
    (function () {
        const RECOVER_URL = @js($recoverUrl);

        function ensureBanner() {
            if (document.getElementById('owwa-session-expired-banner')) {
                return;
            }

            const banner = document.createElement('div');
            banner.id = 'owwa-session-expired-banner';
            banner.setAttribute('role', 'alert');
            banner.style.cssText = [
                'position:fixed',
                'inset-inline:0',
                'top:0',
                'z-index:99999',
                'display:flex',
                'flex-wrap:wrap',
                'gap:0.75rem',
                'align-items:center',
                'justify-content:center',
                'padding:0.85rem 1rem',
                'background:#7f1d1d',
                'color:#fff',
                'font:600 0.95rem/1.4 system-ui,sans-serif',
                'box-shadow:0 2px 10px rgba(0,0,0,.25)',
            ].join(';');

            const message = document.createElement('span');
            message.textContent = 'Your session expired. Reload this page or sign in again to continue.';

            const reloadBtn = document.createElement('button');
            reloadBtn.type = 'button';
            reloadBtn.textContent = 'Reload';
            reloadBtn.style.cssText = 'cursor:pointer;border:0;border-radius:0.4rem;padding:0.4rem 0.75rem;background:#fff;color:#7f1d1d;font:inherit;';
            reloadBtn.addEventListener('click', () => window.location.reload());

            const signInBtn = document.createElement('button');
            signInBtn.type = 'button';
            signInBtn.textContent = 'Sign in again';
            signInBtn.style.cssText = 'cursor:pointer;border:1px solid rgba(255,255,255,.7);border-radius:0.4rem;padding:0.4rem 0.75rem;background:transparent;color:#fff;font:inherit;';
            signInBtn.addEventListener('click', () => window.location.replace(RECOVER_URL));

            banner.append(message, reloadBtn, signInBtn);
            document.body.prepend(banner);
        }

        document.addEventListener('livewire:init', () => {
            if (typeof Livewire === 'undefined' || typeof Livewire.interceptRequest !== 'function') {
                return;
            }

            Livewire.interceptRequest(({ onError }) => {
                onError(({ response, preventDefault }) => {
                    if (! response || response.status !== 419) {
                        return;
                    }

                    preventDefault();
                    ensureBanner();
                });
            });
        });
    })();
</script>
