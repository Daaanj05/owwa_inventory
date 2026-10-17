<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AccountSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unverified_user_sees_pending_verification_and_can_resend(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Pending',
            'last_name' => 'User',
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->assertSee('Settings')
            ->assertSee('Email verification')
            ->assertSee('Pending')
            ->callAction('resendVerification')
            ->assertNotified();

        Notification::assertSentTo($user, \App\Notifications\AccountSetupInviteNotification::class);
    }

    public function test_verified_user_sees_verified_status_without_resend_action(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->assertSee('Verified')
            ->assertActionHidden('resendVerification');
    }

    public function test_change_password_modal_rejects_missing_current_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
            'has_email_authentication' => true,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(AccountSettings::class)->mountAction('changePassword');

        session()->put('filament_email_authentication_code', Hash::make('444444'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->setActionData([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'email_code' => ['code' => '444444'],
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['currentPassword' => 'required']);
    }

    public function test_change_password_modal_updates_password_with_valid_current_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
            'has_email_authentication' => true,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(AccountSettings::class)->mountAction('changePassword');

        session()->put('filament_email_authentication_code', Hash::make('555555'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->setActionData([
                'currentPassword' => 'CurrentPass1',
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'email_code' => ['code' => '555555'],
            ])
            ->callMountedAction()
            ->assertNotified();

        $user->refresh();

        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }

    public function test_change_password_modal_rejects_weak_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
            'has_email_authentication' => true,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(AccountSettings::class)->mountAction('changePassword');

        session()->put('filament_email_authentication_code', Hash::make('666666'));
        session()->put('filament_email_authentication_code_expires_at', now()->addMinutes(4));

        $component
            ->setActionData([
                'currentPassword' => 'CurrentPass1',
                'password' => 'weakpass',
                'passwordConfirmation' => 'weakpass',
                'email_code' => ['code' => '666666'],
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['password']);
    }
}
