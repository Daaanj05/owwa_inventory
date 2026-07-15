<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AccountSettings;
use App\Models\PasswordResetRequest;
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

        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
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

    public function test_pending_password_reset_request_is_shown_on_settings(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->assertSee('Reset requested — awaiting System Admin.');
    }

    public function test_change_password_modal_rejects_missing_current_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->mountAction('changePassword')
            ->setActionData([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
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
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->callAction('changePassword', data: [
                'currentPassword' => 'CurrentPass1',
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
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
        ]);

        $this->actingAs($user);

        Livewire::test(AccountSettings::class)
            ->callAction('changePassword', data: [
                'currentPassword' => 'CurrentPass1',
                'password' => 'weakpass',
                'passwordConfirmation' => 'weakpass',
            ])
            ->assertHasActionErrors(['password']);
    }
}
