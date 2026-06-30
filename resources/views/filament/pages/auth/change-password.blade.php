@php
    use Filament\Facades\Filament;

    $isSystemAdminPanel = Filament::getCurrentPanel()?->getId() === 'system-admin';
@endphp

<div class="owwa-login-wrapper owwa-change-password-page">

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
                <h2 class="owwa-login-form-title">{{ $this->getHeading() }}</h2>
                @if (filled($this->getSubheading()))
                    <p class="owwa-login-form-subtitle">{{ $this->getSubheading() }}</p>
                @endif
            </div>

            <div class="owwa-change-password-notice" role="status">
                <svg class="owwa-change-password-notice-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span>Required before you can access the system.</span>
            </div>

            <div class="owwa-change-password-form">
                {{ $this->content }}
            </div>

            <p class="owwa-login-footer-note">
                OWWA-4A personnel only. Unauthorized access is strictly prohibited.
            </p>
        </div>
    </div>

</div>

@if (!$this instanceof \Filament\Tables\Contracts\HasTable)
    <x-filament-actions::modals />
@endif
