@php
    use Filament\Facades\Filament;

    $isSystemAdminPanel = Filament::getCurrentPanel()?->getId() === 'system-admin';
@endphp

<div class="owwa-login-wrapper">

    @include('filament.pages.auth.partials.brand-panel', ['isSystemAdminPanel' => $isSystemAdminPanel])

    {{-- Right form panel --}}
    <div class="owwa-login-form-panel">

        {{-- Watermark logo in background --}}
        <div class="owwa-login-watermark">
            <img src="{{ asset('images/owwa-4a_logo_transparent.png') }}" alt="">
        </div>

        <div class="owwa-login-form-inner">

            {{-- Mobile brand (hidden on desktop) --}}
            <div class="owwa-login-mobile-brand">
                <img src="{{ asset('images/owwa-4a_logo_transparent.png') }}" alt="OWWA-4A"
                    style="width:2rem;height:2rem;object-fit:contain;flex-shrink:0">
                <span class="owwa-login-mobile-name">OWWA-4A Inventory</span>
            </div>

            <div class="owwa-login-form-header">
                <h2 class="owwa-login-form-title">Welcome!</h2>
            </div>

            @if (request()->boolean('logged_out') || request()->boolean('reauth'))
                <div class="owwa-login-verified-banner" role="status">
                    @if (request()->boolean('logged_out'))
                        Signed out due to inactivity. Please sign in again.
                    @else
                        Signed out. Please sign in again.
                    @endif
                </div>
            @endif

            @if (session('status') === 'email-verified')
                <div class="owwa-login-verified-banner" role="status">
                    {{ \App\Support\FriendlyMessages::emailVerificationSuccess() }}
                </div>
            @endif

            @if (session('verification_error'))
                <div class="owwa-login-error" role="alert">
                    <p class="owwa-login-error-title">Verification link problem</p>
                    <p class="owwa-login-error-text">{{ session('verification_error') }}</p>
                </div>
            @endif

            <form wire:submit="authenticate" class="owwa-login-form">
                <x-login-outlined-input label="{{ __('filament-panels::auth/pages/login.form.email.label') }}"
                    name="data.email" type="email" />
                <x-login-outlined-input label="{{ __('filament-panels::auth/pages/login.form.password.label') }}"
                    name="data.password" type="password" :revealable="filament()->arePasswordsRevealable()" />
                @if (filament()->hasPasswordReset())
                    <p class="owwa-login-forgot">
                        <a href="{{ filament()->getRequestPasswordResetUrl() }}" class="owwa-login-forgot-link" tabindex="-1">
                            {{ __('filament-panels::auth/pages/login.actions.request_password_reset.label') }}
                        </a>
                    </p>
                @endif
                <label class="owwa-login-remember">
                    <input type="checkbox" wire:model="data.remember" class="owwa-login-remember-input">
                    <span>{{ __('filament-panels::auth/pages/login.form.remember.label') }}</span>
                </label>
                <button type="submit" class="owwa-login-submit-btn" wire:loading.attr="disabled"
                    wire:loading.attr="aria-busy" wire:target="authenticate">
                    <span wire:loading.remove wire:target="authenticate">
                        {{ __('filament-panels::auth/pages/login.form.actions.authenticate.label') }}
                    </span>
                    <span wire:loading.inline-flex wire:target="authenticate" wire:cloak
                        style="align-items:center;gap:.6rem;">
                        <svg aria-hidden="true" viewBox="0 0 24 24"
                            style="width:1.25rem;height:1.25rem;animation:owwa-spin 1s linear infinite;">
                            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3"
                                opacity=".25"></circle>
                            <path fill="currentColor" opacity=".9" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2z">
                            </path>
                        </svg>
                        <span>Signing in…</span>
                    </span>
                </button>
            </form>

            @if (session('panel_access_error'))
                <div class="owwa-login-error" role="alert">
                    <p class="owwa-login-error-title">Wrong account for this portal</p>
                    <p class="owwa-login-error-text">{{ session('panel_access_error') }}</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="owwa-login-error" role="alert">
                    <p class="owwa-login-error-title">Sign-in failed</p>
                    <p class="owwa-login-error-text">The email or password you entered is incorrect. Please double-check your credentials and try again.</p>
                </div>
            @endif

            @if (!$this instanceof \Filament\Tables\Contracts\HasTable)
                <x-filament-actions::modals />
            @endif

            <p class="owwa-login-footer-note">
                OWWA-4A personnel only. Unauthorized access is strictly prohibited.
            </p>
        </div>
    </div>

</div>

@php
    $csrfUrl = route('session.csrf');
    $loginUrl = \Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getLoginUrl() ?? url('/login');
@endphp
<script>
    (function () {
        const CSRF_URL = @js($csrfUrl);
        const LOGIN_URL = @js($loginUrl);
        const REAUTH_FLAG = 'owwa_login_419_reauth';

        function applyCsrfToken(token) {
            if (! token || typeof token !== 'string') {
                return;
            }

            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                meta.setAttribute('content', token);
            }

            document.querySelectorAll('[data-csrf]').forEach((el) => {
                el.setAttribute('data-csrf', token);
            });

            if (window.livewireScriptConfig) {
                window.livewireScriptConfig.csrf = token;
            }
        }

        async function syncCsrf() {
            try {
                const response = await fetch(CSRF_URL, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (! response.ok) {
                    return false;
                }

                const payload = await response.json();
                applyCsrfToken(payload?.token);

                return true;
            } catch (e) {
                return false;
            }
        }

        function hardReauth() {
            try {
                if (sessionStorage.getItem(REAUTH_FLAG) === '1') {
                    sessionStorage.removeItem(REAUTH_FLAG);

                    return;
                }

                sessionStorage.setItem(REAUTH_FLAG, '1');
            } catch (e) {
                // sessionStorage may be unavailable
            }

            const url = new URL(LOGIN_URL, window.location.origin);
            url.searchParams.set('reauth', '1');
            url.searchParams.set('_', String(Date.now()));
            window.location.replace(url.toString());
        }

        document.addEventListener('livewire:init', () => {
            syncCsrf();

            if (typeof Livewire === 'undefined' || typeof Livewire.interceptRequest !== 'function') {
                return;
            }

            Livewire.interceptRequest(({ onError, onSuccess }) => {
                onSuccess(() => {
                    try {
                        sessionStorage.removeItem(REAUTH_FLAG);
                    } catch (e) {
                        // ignore
                    }
                });

                onError(({ response, preventDefault }) => {
                    if (! response || response.status !== 419) {
                        return;
                    }

                    preventDefault();
                    syncCsrf().finally(() => hardReauth());
                });
            });
        });

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                syncCsrf();
            }
        });

        window.addEventListener('focus', () => {
            syncCsrf();
        });
    })();
</script>
