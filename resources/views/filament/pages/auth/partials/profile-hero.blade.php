@php
    $user = $this->profileUser();
@endphp

<div class="owwa-account-shell">
    <div class="owwa-profile-hero">
        <div class="owwa-profile-hero-avatar" aria-hidden="true">
            {{ $this->profileInitials() }}
        </div>
        <div class="owwa-profile-hero-body">
            <div class="owwa-profile-hero-top">
                <h2 class="owwa-profile-hero-name">{{ $this->profileDisplayName() }}</h2>
                <span @class(['owwa-account-badge', $this->roleBadgeClass()])>
                    {{ \App\Filament\Pages\Auth\EditProfile::roleLabel($user) }}
                </span>
            </div>
            <p class="owwa-profile-hero-email">{{ $user->email }}</p>
        </div>
    </div>
</div>
