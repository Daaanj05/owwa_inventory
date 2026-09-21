<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class LoginCsrfSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_csrf_endpoint_returns_current_session_token(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk();

        $this->getJson(route('session.csrf'))
            ->assertOk()
            ->assertJsonPath('token', session()->token());
    }

    public function test_login_page_includes_csrf_sync_script(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('session\/csrf', false)
            ->assertSee('owwa_login_419_reauth', false)
            ->assertSee('function syncCsrf', false);
    }

    public function test_login_page_shows_reauth_status_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(route('filament.admin.auth.login', ['reauth' => 1]))
            ->assertOk()
            ->assertSee('Signed out. Please sign in again.', false);
    }

    public function test_login_page_shows_logged_out_status_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(route('filament.admin.auth.login', ['logged_out' => 1]))
            ->assertOk()
            ->assertSee('Signed out due to inactivity. Please sign in again.', false);
    }

    public function test_login_succeeds_after_session_recover_and_fresh_login_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'recover.login@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $this->withSession([]);
        Auth::login($user);

        $this->actingAs($user)
            ->get(route('session.recover'))
            ->assertRedirect(url('/login').'?reauth=1');

        $this->assertGuest();

        $this->get(route('filament.admin.auth.login', ['reauth' => 1]))->assertOk();

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'recover.login@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
    }
}
