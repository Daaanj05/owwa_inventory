<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AccountSettings;
use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordChangeMultiFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_password_change_without_mfa_succeeds(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->withoutMultiFactorAuthentication()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'password',
        ]);

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('changePassword')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }

    public function test_forced_password_change_with_mfa_requires_factor_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'has_email_authentication' => true,
            'password' => 'password',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(ChangePassword::class);

        session()->put('filament_email_authentication_code', Hash::make('111111'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'email_code' => [
                    'code' => '000000',
                ],
            ])
            ->call('changePassword')
            ->assertHasFormErrors();

        $this->assertTrue($user->fresh()->mustChangePassword());
    }

    public function test_forced_password_change_succeeds_with_valid_email_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'has_email_authentication' => true,
            'password' => 'password',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(ChangePassword::class);

        session()->put('filament_email_authentication_code', Hash::make('222222'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'email_code' => [
                    'code' => '222222',
                ],
            ])
            ->call('changePassword')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }

    public function test_settings_password_change_requires_factor_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'has_email_authentication' => true,
            'password' => 'password',
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(AccountSettings::class)->mountAction('changePassword');

        session()->put('filament_email_authentication_code', Hash::make('333333'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->setActionData([
                'currentPassword' => 'password',
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'email_code' => [
                    'code' => '000000',
                ],
            ])
            ->callMountedAction()
            ->assertHasActionErrors();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
