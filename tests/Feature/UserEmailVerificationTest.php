<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Office;
use App\Models\User;
use App\Notifications\UserWelcomeNotification;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_create_user_sends_single_welcome_notification_with_verification_url(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Jane',
                'last_name' => 'Employee',
                'email' => 'jane.employee@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user = User::query()->where('email', 'jane.employee@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, UserWelcomeNotification::class);
        Notification::assertNotSentTo($user, VerifyEmail::class);

        Notification::assertSentTo($user, UserWelcomeNotification::class, function (UserWelcomeNotification $notification): bool {
            return filled($notification->verificationUrl)
                && str_contains($notification->verificationUrl, '/email/verify/')
                && ! str_contains($notification->verificationUrl, '/admin/email-verification/')
                && filled($notification->temporaryPassword)
                && filled($notification->panelLoginUrl);
        });
    }

    public function test_guest_verification_link_marks_email_verified_without_login(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email' => 'guest.verify@example.com',
        ]);

        $url = User::guestEmailVerificationUrlFor($user);

        $this->get($url)
            ->assertRedirect(User::panelLoginUrlFor($user))
            ->assertSessionHas('status', 'email-verified');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_guest_verification_link_requires_valid_signature(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'unsigned@example.com',
        ]);

        $this->get('/email/verify/'.$user->id.'/'.sha1($user->getEmailForVerification()))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_user_cannot_login_and_sees_verification_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'password' => 'password',
            'email' => 'unverified@example.com',
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'unverified@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => 'Please verify your email address before signing in.']);
    }

    public function test_login_with_unknown_email_shows_generic_failure_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'nobody@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }

    public function test_unverified_user_with_wrong_password_shows_generic_failure_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'password' => 'password',
            'email' => 'unverified@example.com',
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'unverified@example.com',
                'password' => 'wrong-password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }

    public function test_verified_user_can_access_admin_panel(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_create_user_toast_includes_temporary_password_backup(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Backup',
                'last_name' => 'Password',
                'email' => 'backup.password@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('User created');

        $this->assertDatabaseHas(User::class, [
            'email' => 'backup.password@example.com',
            'email_verified_at' => null,
            'must_change_password' => true,
        ]);
    }
}
