<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\ItemCategory;
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

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('session\/csrf', false)
            ->assertSee('owwa_login_419_reauth', false)
            ->assertSee('function syncCsrf', false)
            ->assertDontSee("addEventListener('focus'", false)
            ->assertDontSee('EchoFactory', false);
    }

    public function test_login_page_shows_reauth_status_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->followingRedirects()
            ->get(route('session.recover'))
            ->assertOk()
            ->assertSee('Signed out. Please sign in again.', false);
    }

    public function test_login_page_shows_logged_out_status_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->followingRedirects()
            ->get(route('session.recover', ['reason' => 'idle_timeout']))
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
            ->assertRedirect(url('/login'))
            ->assertSessionHas('auth_signed_out', 'expired');

        $this->assertGuest();

        $this->get(route('filament.admin.auth.login'))->assertOk();

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

    public function test_category_dashboard_starts_echo(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(InventoryCategoryDashboard::getUrl(['category' => $category->id]))
            ->assertOk()
            ->assertSee('EchoFactory', false);
    }

    public function test_employee_dashboard_starts_echo(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(Filament::getPanel('admin')->getUrl())
            ->assertOk()
            ->assertSee('EchoFactory', false);
    }

    public function test_requisitions_list_starts_echo(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(RequisitionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('EchoFactory', false);
    }
}
