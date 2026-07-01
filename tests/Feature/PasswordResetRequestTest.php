<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetRequestDatabaseNotification;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_password_reset_request_creates_pending_row_and_notifies_system_admins(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
            'email_verified_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $employee->email])
            ->call('request')
            ->assertNotified();

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);

        Notification::assertSentTo($admin, PasswordResetRequestDatabaseNotification::class);
        Notification::assertNotSentTo($employee, FilamentResetPassword::class);
    }

    public function test_unknown_email_shows_success_without_creating_request(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'unknown@example.com'])
            ->call('request')
            ->assertNotified();

        $this->assertDatabaseCount('password_reset_requests', 0);
        Notification::assertNothingSent();
    }

    public function test_system_admin_login_has_no_forgot_password_link(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $this->assertFalse(Filament::getCurrentPanel()->hasPasswordReset());

        Livewire::test(Login::class)
            ->assertDontSee(__('filament-panels::auth/pages/login.actions.request_password_reset.label'));
    }

    public function test_admin_send_password_reset_email_queues_notification(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
            'email_verified_at' => now(),
        ]);
        $request = PasswordResetRequest::query()->create([
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('view')->table($employee))
            ->callAction(TestAction::make('sendPasswordResetEmail'))
            ->assertNotified();

        Notification::assertSentTo($employee, FilamentResetPassword::class);

        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_SENT, $request->status);
        $this->assertSame($admin->id, $request->handled_by);
    }

    public function test_admin_can_dismiss_password_reset_request(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);
        $request = PasswordResetRequest::query()->create([
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('view')->table($employee))
            ->callAction(TestAction::make('dismissPasswordResetRequest'))
            ->assertNotified();

        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_DISMISSED, $request->status);
        $this->assertSame($admin->id, $request->handled_by);
    }

    public function test_prune_command_deletes_old_password_reset_requests(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $oldRequest = PasswordResetRequest::query()->create([
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now()->subDays(40),
        ]);
        $oldRequest->forceFill(['created_at' => now()->subDays(40)])->save();

        $recentRequest = PasswordResetRequest::query()->create([
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->artisan('password-reset-requests:prune')->assertSuccessful();

        $this->assertDatabaseMissing('password_reset_requests', ['id' => $oldRequest->id]);
        $this->assertDatabaseHas('password_reset_requests', ['id' => $recentRequest->id]);
    }

    public function test_users_table_shows_reset_requested_badge(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);
        PasswordResetRequest::query()->create([
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$employee])
            ->assertTableColumnStateSet('pendingPasswordResetRequest.requested_at', 'Reset requested', $employee);
    }
}
