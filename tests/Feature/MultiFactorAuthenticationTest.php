<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\SetUpRequiredMultiFactorAuthentication;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Livewire\Livewire;
use Tests\TestCase;

class MultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_mfa_is_sent_to_setup_after_login(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'needs.mfa@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'needs.mfa@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);

        $this->get(Filament::getUrl())
            ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function test_either_email_or_app_factor_satisfies_requirement(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'has_email_authentication' => true,
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        $this->get(Filament::getUrl())->assertOk();

        $user->forceFill([
            'has_email_authentication' => false,
            'app_authentication_secret' => null,
        ])->save();

        $this->get(Filament::getUrl())
            ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function test_must_change_password_user_is_redirected_away_from_mfa_setup(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->withoutMultiFactorAuthentication()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $request = Request::create('/multi-factor-authentication/set-up', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(function () use ($request) {
            $route = new IlluminateRoute(['GET'], '/multi-factor-authentication/set-up', [
                'as' => 'filament.admin.auth.multi-factor-authentication.set-up-required',
            ]);
            $route->bind($request);

            return $route;
        });

        $response = app(EnsurePasswordChanged::class)->handle($request, fn () => response('ok'));

        $this->assertTrue($response->isRedirect());
    }

    public function test_setup_page_renders_owwa_shell_with_cancel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_EMPLOYEE,
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
            ->assertSee('Enable authenticator or email codes before continuing.')
            ->assertSee('OWWA IV-A CALABARZON Inventory System')
            ->assertSee('Cancel');
    }

    public function test_mfa_cancel_logs_out(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_EMPLOYEE,
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
            ->call('cancelSetup')
            ->assertRedirect();

        $this->assertGuest();
    }
}
