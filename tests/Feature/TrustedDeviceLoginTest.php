<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AccountSettings;
use App\Filament\Pages\Auth\Login;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrustedDeviceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TrustedDeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_device_skips_mfa_challenge_on_login(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'trusted@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'must_change_password' => false,
            'has_email_authentication' => true,
        ]);

        $token = 'trusted-device-token-abcdefghijklmnopqrstuvwxyz012345';
        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => 'PHPUnit',
            'expires_at' => now()->addDays(15),
            'last_used_at' => now(),
        ]);

        $component = Livewire::test(Login::class);

        // Livewire's test broker does not inherit TestCase default cookies.
        request()->cookies->set(TrustedDeviceService::COOKIE, $token);

        $component
            ->set('data', [
                'email' => 'trusted@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertSet('userUndertakingMultiFactorAuthentication', null);

        $this->assertAuthenticatedAs($user);
    }

    public function test_mfa_challenge_can_remember_device(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'remember.device@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'must_change_password' => false,
            'has_email_authentication' => true,
        ]);

        $component = Livewire::test(Login::class)
            ->set('data', [
                'email' => 'remember.device@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate');

        $this->assertNotNull($component->get('userUndertakingMultiFactorAuthentication'));

        session()->put('filament_email_authentication_code', Hash::make('555555'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->set('data.email', 'remember.device@example.com')
            ->set('data.password', 'password')
            ->set('data.multiFactor', [
                'email_code' => [
                    'code' => '555555',
                ],
                'rememberDevice' => true,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())
                ->contains(fn ($cookie): bool => $cookie->getName() === TrustedDeviceService::COOKIE)
        );
    }

    public function test_expired_trusted_device_still_requires_mfa(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'expired.device@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'must_change_password' => false,
            'has_email_authentication' => true,
        ]);

        $token = 'expired-device-token-abcdefghijklmnopqrstuvwxyz012345';
        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => 'Expired',
            'expires_at' => now()->subDay(),
            'last_used_at' => now()->subDays(2),
        ]);

        $component = Livewire::test(Login::class);

        request()->cookies->set(TrustedDeviceService::COOKIE, $token);

        $component
            ->set('data', [
                'email' => 'expired.device@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));

        $this->assertGuest();
    }

    public function test_settings_can_revoke_trusted_device(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'must_change_password' => false,
            'has_email_authentication' => true,
        ]);

        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'revoke-me-token-abcdefghijklmnopqrstuvwxyz0123'),
            'label' => 'Office PC',
            'expires_at' => now()->addDays(10),
            'last_used_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->assertSee('Trusted devices')
            ->assertSee('Office PC')
            ->call('revokeTrustedDevice', $device->id)
            ->assertNotified();

        $this->assertDatabaseMissing('trusted_devices', ['id' => $device->id]);
    }
}
