<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_notification_is_sent_for_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'reset.me@example.com',
        ]);

        Password::sendResetLink(['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_password_reset_does_not_send_notification_for_unknown_email(): void
    {
        Notification::fake();

        Password::sendResetLink(['email' => 'unknown@example.com']);

        Notification::assertNothingSent();
    }

    public function test_password_reset_clears_must_change_password_and_applies_rules(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'reset.me@example.com',
        ]);

        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, [
            'email' => $user->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'weakpass',
                'passwordConfirmation' => 'weakpass',
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['password']);

        Livewire::test(ResetPassword::class, [
            'email' => $user->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();

        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }
}
