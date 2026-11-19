<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('filament-login:'.sha1(strtolower('rate.limit@example.com').'|127.0.0.1'));
        RateLimiter::clear('filament-login:'.sha1(strtolower('rate.limit.a@example.com').'|127.0.0.1'));
        RateLimiter::clear('filament-login:'.sha1(strtolower('rate.limit.b@example.com').'|127.0.0.1'));
        RateLimiter::clear('filament-login-ip:'.sha1('127.0.0.1'));
    }

    public function test_login_is_rate_limited_after_too_many_failures(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email' => 'rate.limit@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('data', [
                    'email' => 'rate.limit@example.com',
                    'password' => 'wrong-password',
                    'remember' => false,
                ])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'rate.limit@example.com',
                'password' => 'wrong-password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertTrue(
            RateLimiter::tooManyAttempts(
                'filament-login:'.sha1(strtolower('rate.limit@example.com').'|127.0.0.1'),
                5,
            ),
        );
    }

    public function test_login_is_rate_limited_by_ip_across_emails(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config([
            'inventory.login_ip_max_attempts' => 4,
            'inventory.login_ip_decay_seconds' => 600,
        ]);

        $office = Office::factory()->create();
        $ipThrottleKey = 'filament-login-ip:'.sha1('127.0.0.1');

        foreach (['rate.limit.a@example.com', 'rate.limit.b@example.com'] as $email) {
            User::factory()->withoutMultiFactorAuthentication()->create([
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'email' => $email,
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
        }

        foreach (['rate.limit.a@example.com', 'rate.limit.b@example.com'] as $email) {
            for ($i = 0; $i < 2; $i++) {
                Livewire::test(Login::class)
                    ->set('data', [
                        'email' => $email,
                        'password' => 'wrong-password',
                        'remember' => false,
                    ])
                    ->call('authenticate')
                    ->assertHasFormErrors(['email']);
            }
        }

        $this->assertSame(4, RateLimiter::attempts($ipThrottleKey));
        $this->assertTrue(RateLimiter::tooManyAttempts($ipThrottleKey, 4));

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'rate.limit.a@example.com',
                'password' => 'wrong-password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }

    public function test_login_asks_for_a_valid_email_when_the_at_sign_is_missing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['juangmail.com', 'juanowwa.gov.ph'] as $email) {
            $throttleKey = 'filament-login:'.sha1(strtolower($email).'|127.0.0.1');
            RateLimiter::clear($throttleKey);

            Livewire::test(Login::class)
                ->set('data', [
                    'email' => $email,
                    'password' => 'password',
                    'remember' => false,
                ])
                ->call('authenticate')
                ->assertHasFormErrors(['email' => 'email'])
                ->assertSee(Login::INVALID_EMAIL_MESSAGE)
                ->assertDontSee('The email or password you entered is incorrect.');

            $this->assertSame(0, RateLimiter::attempts($throttleKey));
        }
    }
}
