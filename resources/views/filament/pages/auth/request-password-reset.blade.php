@php
    use App\Support\FriendlyMessages;
    use Filament\Facades\Filament;

    $isSystemAdminPanel = Filament::getCurrentPanel()?->getId() === 'system-admin';
@endphp

<div class="owwa-login-wrapper owwa-password-reset-page">

    @include('filament.pages.auth.partials.brand-panel', ['isSystemAdminPanel' => $isSystemAdminPanel])

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
                        &larr; {{ __('filament-panels::auth/pages/password-reset/request-password-reset.actions.login.label') }}
                    </a>
                </p>
            </div>

            <p class="owwa-password-reset-helper">
                Enter your registered email address. {{ FriendlyMessages::passwordResetRequestSubmitted() }}
            </p>

            <div class="owwa-password-reset-form">
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
