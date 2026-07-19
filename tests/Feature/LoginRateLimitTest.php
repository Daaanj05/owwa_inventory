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
    }

    public function test_login_is_rate_limited_after_too_many_failures(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->create([
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
}
