<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Notifications\SignInEmailChangedNotification;
use App\Notifications\UserWelcomeNotification;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create'))
            ->fillForm([
                'first_name' => 'Jane',
                'last_name' => 'Employee',
                'email' => 'jane.employee@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => $department->id,
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
            ->assertRedirect('/login')
            ->assertSessionHas('verification_error');

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
            ->assertHasFormErrors(['email' => \App\Support\FriendlyMessages::emailNotVerifiedLogin()]);
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
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create'))
            ->fillForm([
                'first_name' => 'Backup',
                'last_name' => 'Password',
                'email' => 'backup.password@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => $department->id,
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

    public function test_admin_email_change_clears_verification_notifies_and_revokes_sessions(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'first_name' => 'Jane',
            'last_name' => 'Public',
            'email' => 'jane.old@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => 'old-remember-token',
        ]);

        DB::table('sessions')->insert([
            'id' => 'employee-session-1',
            'user_id' => $employee->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('edit')->table($employee))
            ->fillForm([
                'first_name' => 'Jane',
                'last_name' => 'Public',
                'email' => 'jane.new@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => $department->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Sign-in email updated');

        $employee->refresh();

        $this->assertSame('jane.new@example.com', $employee->email);
        $this->assertNull($employee->email_verified_at);
        $this->assertNotSame('old-remember-token', $employee->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'employee-session-1']);

        Notification::assertSentTo($employee, FilamentVerifyEmail::class);
        Notification::assertSentOnDemand(SignInEmailChangedNotification::class, function (SignInEmailChangedNotification $notification, array $channels, object $notifiable): bool {
            return $notification->newEmail === 'jane.new@example.com'
                && ($notifiable->routes['mail'] ?? null) === 'jane.old@example.com';
        });

        $url = User::guestEmailVerificationUrlFor($employee);

        $this->get($url)
            ->assertRedirect(User::panelLoginUrlFor($employee))
            ->assertSessionHas('status', 'email-verified');

        $this->assertNotNull($employee->fresh()->email_verified_at);
    }

    public function test_admin_changed_email_blocks_login_until_verified(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'password' => 'password',
            'email' => 'changed@example.com',
            'email_verified_at' => null,
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'changed@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => \App\Support\FriendlyMessages::emailNotVerifiedLogin()]);
    }

    public function test_admin_save_with_unchanged_email_keeps_verification(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'first_name' => 'Jane',
            'last_name' => 'Public',
            'email' => 'jane.same@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('edit')->table($employee))
            ->fillForm([
                'first_name' => 'Jane',
                'middle_name' => 'Q',
                'last_name' => 'Public',
                'email' => 'jane.same@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => $department->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $employee->refresh();

        $this->assertSame('jane.same@example.com', $employee->email);
        $this->assertNotNull($employee->email_verified_at);
        Notification::assertNotSentTo($employee, FilamentVerifyEmail::class);
        Notification::assertSentOnDemandTimes(SignInEmailChangedNotification::class, 0);
    }
}
