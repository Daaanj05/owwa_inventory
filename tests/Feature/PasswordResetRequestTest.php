<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\PasswordResetCodeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders_branded_centered_layout(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RequestPasswordReset::class)
            ->assertSee('OWWA IV-A CALABARZON Inventory System')
            ->assertDontSee('System Administration')
            ->assertSee(__('filament-panels::auth/pages/password-reset/request-password-reset.heading'));
    }

    public function test_forgot_password_sends_code_automatically(): void
    {
        Notification::fake();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
            'email_verified_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $employee->email])
            ->call('request')
            ->assertNotified()
            ->assertSet('step', 2);

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_SENT,
        ]);

        Notification::assertSentTo($employee, PasswordResetCodeNotification::class);
    }

    public function test_unknown_email_shows_success_without_creating_request(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'unknown@example.com'])
            ->call('request')
            ->assertNotified()
            ->assertSet('step', 2);

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

    public function test_admin_login_shows_forgot_password_link_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(Filament::getCurrentPanel()->hasPasswordReset());

        Livewire::test(Login::class)
            ->assertSee(__('filament-panels::auth/pages/login.actions.request_password_reset.label'))
            ->assertDontSee('I have a reset code')
            ->assertDontSee('Verify email with code');
    }

    public function test_prune_expired_requests_keeps_recent_rows(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $oldRequest = PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now()->subDays(60),
        ]);
        $oldRequest->forceFill(['created_at' => now()->subDays(60)])->save();

        $recentRequest = PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now(),
        ]);

        app(PasswordResetCodeService::class)->pruneExpiredRequests();

        $this->assertDatabaseMissing('password_reset_requests', ['id' => $oldRequest->id]);
        $this->assertDatabaseHas('password_reset_requests', ['id' => $recentRequest->id]);
    }

    public function test_users_table_has_no_pending_reset_column(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertTableColumnDoesNotExist('pendingPasswordResetRequest.requested_at');
    }
}
