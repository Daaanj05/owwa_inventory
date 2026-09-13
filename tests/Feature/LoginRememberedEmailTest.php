<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Office;
use App\Models\User;
use App\Support\LoginRememberedEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

class LoginRememberedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_with_remember_queues_email_cookie(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'remember.me@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'remember.me@example.com',
                'password' => 'password',
                'remember' => true,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertTrue(Cookie::hasQueued(LoginRememberedEmail::COOKIE));
        $queued = collect(Cookie::getQueuedCookies())
            ->first(fn ($cookie) => $cookie->getName() === LoginRememberedEmail::COOKIE);
        $this->assertNotNull($queued);
        $this->assertSame('remember.me@example.com', $queued->getValue());
    }

    public function test_successful_login_without_remember_forgets_email_cookie(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'forget.me@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'forget.me@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertTrue(Cookie::hasQueued(LoginRememberedEmail::COOKIE));
        $queued = collect(Cookie::getQueuedCookies())
            ->first(fn ($cookie) => $cookie->getName() === LoginRememberedEmail::COOKIE);
        $this->assertNotNull($queued);
        $this->assertTrue($queued->getExpiresTime() < time());
    }

    public function test_login_page_prefills_email_from_remember_cookie(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::withCookie(LoginRememberedEmail::COOKIE, 'saved.user@example.com')
            ->test(Login::class);

        $this->assertSame('saved.user@example.com', $component->get('data.email'));
        $this->assertTrue((bool) $component->get('data.remember'));
    }
}
