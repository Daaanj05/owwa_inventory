@php
    $isSystemAdminLogin = \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'system-admin';
@endphp

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

        <h1 class="owwa-login-brand-name">OWWA IV-A CALABARZON Inventory System</h1>
        @if ($isSystemAdminLogin)
            <p class="owwa-login-brand-portal">System Administration</p>
        @endif
        <p class="owwa-login-brand-tagline">Overseas Workers Welfare Administration Regional Welfare Office 4A
        </p>
    </div>

    <div class="owwa-login-deco owwa-login-deco-1"></div>
    <div class="owwa-login-deco owwa-login-deco-2"></div>
</div>
