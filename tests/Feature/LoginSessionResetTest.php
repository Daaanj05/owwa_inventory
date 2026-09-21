<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginSessionResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_page_sets_no_store_cache_headers(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $cacheControl = (string) $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->headers
            ->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    public function test_guest_login_page_sets_session_cookies(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertCookie(config('session.cookie'))
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_login_succeeds_without_forced_session_regenerate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'fresh.login@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'fresh.login@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
    }
}
