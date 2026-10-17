<div class="owwa-login-wrapper owwa-mfa-setup-page">

    @include('filament.pages.auth.partials.brand-panel')

    <div class="owwa-login-form-panel">

        <div class="owwa-login-watermark">
            <img src="{{ asset('images/owwa-4a_logo_transparent.png') }}" alt="">
        </div>

        <div class="owwa-login-form-inner">

            <div class="owwa-login-mobile-brand">
                <img src="{{ asset('images/owwa-4a_logo_transparent.png') }}" alt="OWWA-4A"
                    style="width:2rem;height:2rem;object-fit:contain;flex-shrink:0">
                <span class="owwa-login-mobile-name">OWWA-4A Inventory</span>
            </div>

            <div class="owwa-login-form-header">
                <h2 class="owwa-login-form-title">{{ $this->getHeading() }}</h2>
                @if (filled($this->getSubheading()))
                    <p class="owwa-login-form-subtitle">{{ $this->getSubheading() }}</p>
                @endif
            </div>

            <div class="owwa-change-password-notice" role="status">
                <svg class="owwa-change-password-notice-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
                <span>Enable authenticator or email codes before continuing.</span>
            </div>

            <div class="owwa-mfa-setup-form">
                {{ $this->content }}
            </div>

            @if (! $this instanceof \Filament\Tables\Contracts\HasTable)
                <x-filament-actions::modals />
            @endif

            <p class="owwa-login-footer-note">
                OWWA-4A personnel only. Unauthorized access is strictly prohibited.
            </p>
        </div>
    </div>

</div>
