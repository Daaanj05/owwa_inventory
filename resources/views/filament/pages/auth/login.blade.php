@php
    use Filament\Facades\Filament;

    $isSystemAdminPanel = Filament::getCurrentPanel()?->getId() === 'system-admin';
@endphp

<div class="owwa-login-wrapper">

        {{-- Left brand panel --}}
        <div class="owwa-login-brand">
            <div class="owwa-login-brand-inner">

                {{-- Logos row: OWWA-4A + Bagong Pilipinas --}}
                <div class="owwa-login-logos-row">
                    <div class="owwa-login-logo">
                        <img src="{{ asset('images/owwa-4a_logo_transparent.png') }}" alt="OWWA-4A Logo"
                            class="owwa-login-logo-img">
                    </div>
                    <div class="owwa-login-logo">
                        <img src="{{ asset('images/Bagong_Pilipinas_logo.png') }}" alt="Bagong Pilipinas"
                            class="owwa-login-logo-img owwa-login-logo-img--dark">
                    </div>
                </div>

                <h1 class="owwa-login-brand-name">OWWA 4A Calabarzon Inventory</h1>
                <p class="owwa-login-brand-tagline">Overseas Workers Welfare Administration Regional Welfare Office 4A
                </p>

                <div class="owwa-login-brand-divider"></div>

                <div class="owwa-login-brand-features">
                    <div class="owwa-login-feature">
                        <div class="owwa-login-feature-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                            </svg>
                        </div>
                        <span>{{ $isSystemAdminPanel ? 'User & role management' : 'Real-time stock tracking' }}</span>
                    </div>
                    <div class="owwa-login-feature">
                        <div class="owwa-login-feature-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <span>{{ $isSystemAdminPanel ? 'Fiscal year & master data setup' : 'Consumption analytics' }}</span>
                    </div>
                    <div class="owwa-login-feature">
                        <div class="owwa-login-feature-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                        </div>
                        <span>{{ $isSystemAdminPanel ? 'Audit & activity logs' : 'AI procurement recommendations' }}</span>
                    </div>
                    <div class="owwa-login-feature">
                        <div class="owwa-login-feature-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <span>{{ $isSystemAdminPanel ? 'Setup & access governance' : 'COA-compliant reporting' }}</span>
                    </div>
                </div>
            </div>

            <div class="owwa-login-deco owwa-login-deco-1"></div>
            <div class="owwa-login-deco owwa-login-deco-2"></div>
        </div>

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

                <form wire:submit="authenticate" class="owwa-login-form">
                    <x-login-outlined-input label="{{ __('filament-panels::auth/pages/login.form.email.label') }}"
                        name="data.email" type="email" />
                    <x-login-outlined-input label="{{ __('filament-panels::auth/pages/login.form.password.label') }}"
                        name="data.password" type="password" :revealable="filament()->arePasswordsRevealable()" />
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
</div>