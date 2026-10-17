@php
    $user = $this->profileUser();
    $verification = $this->verificationBadge();
    $trustedDevices = $this->activeTrustedDevices();
    $trustedDays = app(\App\Services\TrustedDeviceService::class)->days();
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
                                Finish account setup from the link in your email before signing in on other devices.
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

            <section class="owwa-settings-card">
                <div class="owwa-settings-row">
                    <div class="owwa-settings-row-icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />
                        </svg>
                    </div>
                    <div class="owwa-settings-row-copy" style="flex:1;">
                        <h3 class="owwa-settings-row-title">Trusted devices</h3>
                        <p class="owwa-settings-row-text">
                            Browsers you marked “Remember this device” can skip the verification code for {{ $trustedDays }} days.
                        </p>
                        @if ($trustedDevices->isEmpty())
                            <p class="owwa-settings-row-text" style="margin-top:.5rem;">No trusted devices right now.</p>
                        @else
                            <ul style="margin:.75rem 0 0;padding:0;list-style:none;display:grid;gap:.5rem;">
                                @foreach ($trustedDevices as $device)
                                    <li style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:.65rem .75rem;border:1px solid rgba(15,23,42,.08);border-radius:.5rem;">
                                        <div>
                                            <div style="font-size:.875rem;font-weight:600;color:#0f172a;">
                                                {{ $device->label ?: 'Trusted browser' }}
                                            </div>
                                            <div style="font-size:.75rem;color:#64748b;margin-top:.15rem;">
                                                Expires {{ $device->expires_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                                @if ($device->last_used_at)
                                                    · Last used {{ $device->last_used_at->diffForHumans() }}
                                                @endif
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="fi-btn fi-size-sm fi-btn-color-gray fi-outlined"
                                            wire:click="revokeTrustedDevice({{ $device->id }})"
                                            wire:confirm="Remove this trusted device? You will need a verification code next time on that browser."
                                        >
                                            Remove
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
