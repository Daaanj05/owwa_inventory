<div class="owwa-login-wrapper owwa-verify-email-page">

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
                <p class="owwa-password-reset-back">
                    <a href="{{ filament()->getLoginUrl() }}" class="owwa-login-forgot-link">
                        &larr; Back to sign in
                    </a>
                </p>
            </div>

            @if (filled($this->getSubheading()))
                <p class="owwa-password-reset-helper">{{ $this->getSubheading() }}</p>
            @endif

            <div class="owwa-password-reset-form" wire:key="account-setup-step-{{ $this->step }}">
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
