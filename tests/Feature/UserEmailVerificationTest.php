<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\VerifyEmailWithCode;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Notifications\AccountSetupInviteNotification;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\SignInEmailChangedNotification;
use App\Support\FriendlyMessages;
use App\Support\OneTimeCode;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_create_user_sends_setup_invite_without_code(): void
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
        $this->assertNull($user->email_verification_code_hash);
        $this->assertFalse($user->must_change_password);

        Notification::assertSentTo($user, AccountSetupInviteNotification::class);
        Notification::assertNotSentTo($user, EmailVerificationCodeNotification::class);
    }

    public function test_setup_wizard_completes_with_code_and_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->unverified()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email' => 'guest.verify@example.com',
            'password' => 'OldInternal1!',
        ]);

        Notification::fake();

        $component = Livewire::test(VerifyEmailWithCode::class)
            ->fillForm(['email' => 'guest.verify@example.com'])
            ->call('advance')
            ->assertHasNoFormErrors()
            ->assertSet('step', 2)
            ->assertSee('Verification code')
            ->assertSee('Verify code')
            ->assertNotified();

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);

        $code = '123456';
        $user->forceFill([
            'email_verification_code_hash' => OneTimeCode::hash($code),
            'email_verification_code_expires_at' => now()->addHour(),
        ])->save();

        $component
            ->fillForm(['code' => $code])
            ->call('advance')
            ->assertHasNoFormErrors()
            ->assertSet('step', 3)
            ->assertSee('Choose your password')
            ->fillForm([
                'password' => 'ChosenPass1!',
                'passwordConfirmation' => 'ChosenPass1!',
            ])
            ->call('advance')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('ChosenPass1!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_user_cannot_login_and_sees_verification_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->unverified()->create([
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
            ->assertHasFormErrors(['email']);

        $this->assertStringContainsString(
            'finish setting up your account',
            strtolower(FriendlyMessages::emailNotVerifiedLogin(User::panelSetupUrlFor($user))),
        );
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

    public function test_create_user_toast_does_not_include_temporary_password(): void
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
            ->assertNotified();

        $user = User::query()->where('email', 'backup.password@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->must_change_password);
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

        Notification::assertSentTo($employee, AccountSetupInviteNotification::class);
        Notification::assertSentOnDemand(SignInEmailChangedNotification::class, function (SignInEmailChangedNotification $notification, array $channels, object $notifiable): bool {
            return $notification->newEmail === 'jane.new@example.com'
                && ($notifiable->routes['mail'] ?? null) === 'jane.old@example.com';
        });
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
            'has_email_authentication' => false,
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'changed@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
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
        Notification::assertNotSentTo($employee, AccountSetupInviteNotification::class);
        Notification::assertSentOnDemandTimes(SignInEmailChangedNotification::class, 0);
    }

    public function test_resend_setup_email_for_unverified_user(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'pending.resend@example.com',
            'password' => 'OldTemp1!',
            'must_change_password' => false,
        ]);
        $previousHash = $employee->password;

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('view')->table($employee))
            ->callAction(TestAction::make('resendVerification'))
            ->assertNotified();

        $this->assertSame($previousHash, $employee->fresh()->password);
        Notification::assertSentTo($employee, AccountSetupInviteNotification::class);
    }
}
