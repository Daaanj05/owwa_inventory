@php
    $user = $this->profileUser();
    $verification = $this->verificationBadge();
    $hasPendingReset = $user->pendingPasswordResetRequest !== null;
@endphp

<x-filament-panels::page>
    <div class="owwa-account-shell">
        <div class="owwa-settings-stack">
            <section class="owwa-settings-card">
                <div class="owwa-settings-row">
                    <div class="owwa-settings-row-icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                        </svg>
                    </div>
                    <div class="owwa-settings-row-copy">
                        <h3 class="owwa-settings-row-title">Email verification</h3>
                        <p class="owwa-settings-row-text">
                            @if ($user->hasVerifiedEmail())
                                Your email address is verified and can be used to sign in.
                            @else
                                Verify your email to secure your account and receive system notifications.
                            @endif
                        </p>
                    </div>
                    <div class="owwa-settings-row-action">
                        <span @class(['owwa-account-badge', $verification['class']])>{{ $verification['label'] }}</span>
                        @if (! $user->hasVerifiedEmail())
                            {{ $this->resendVerificationAction }}
                        @endif
                    </div>
                </div>
            </section>

            @if ($hasPendingReset)
                <section class="owwa-settings-card">
                    <div class="owwa-settings-row">
                        <div class="owwa-settings-row-icon" aria-hidden="true">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                            </svg>
                        </div>
                        <div class="owwa-settings-row-copy">
                            <h3 class="owwa-settings-row-title">Password reset</h3>
                            <p class="owwa-settings-row-text">Reset requested — awaiting System Admin.</p>
                        </div>
                        <div class="owwa-settings-row-action">
                            <span class="owwa-account-badge owwa-account-badge--neutral">Pending</span>
                        </div>
                    </div>
                </section>
            @endif

            <section class="owwa-settings-card">
                <div class="owwa-settings-row">
                    <div class="owwa-settings-row-icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <div class="owwa-settings-row-copy">
                        <h3 class="owwa-settings-row-title">Password</h3>
                        <p class="owwa-settings-row-text">Update your password regularly. You will need your current password to continue.</p>
                    </div>
                    <div class="owwa-settings-row-action">
                        {{ $this->changePasswordAction }}
                    </div>
                </div>
            </section>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
