<?php

namespace Tests\Feature;

use App\Listeners\LogUserLogin;
use App\Models\Office;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class UserSessionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_creates_user_log_and_session_key(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        Auth::login($user);

        $this->assertDatabaseHas('user_logs', [
            'user_id' => $user->id,
            'logout_reason' => null,
        ]);

        $log = UserLog::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->last_activity_at);
        $this->assertSame($log->id, session('audit_user_log_id'));
    }

    public function test_logout_closes_session_with_manual_reason(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        Auth::login($user);

        $logId = (int) session('audit_user_log_id');
        Auth::logout();

        $log = UserLog::query()->findOrFail($logId);
        $this->assertNotNull($log->logged_out_at);
        $this->assertSame(UserLog::LOGOUT_MANUAL, $log->logout_reason);
    }

    public function test_duplicate_login_event_does_not_create_second_log(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        app(LogUserLogin::class)->handle(new Login('web', $user, false));
        $firstLogId = (int) session('audit_user_log_id');

        app(LogUserLogin::class)->handle(new Login('web', $user, false));
        $secondLogId = (int) session('audit_user_log_id');

        $this->assertSame($firstLogId, $secondLogId);
        $this->assertSame(1, UserLog::query()->where('user_id', $user->id)->count());
    }

    public function test_new_login_closes_previous_open_session(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        app(LogUserLogin::class)->handle(new Login('web', $user, false));
        $firstLogId = (int) session('audit_user_log_id');

        UserLog::query()->whereKey($firstLogId)->update([
            'logged_out_at' => now(),
            'logout_reason' => UserLog::LOGOUT_MANUAL,
        ]);
        Session::forget('audit_user_log_id');

        app(LogUserLogin::class)->handle(new Login('web', $user, false));
        $secondLogId = (int) session('audit_user_log_id');

        $this->assertNotSame($firstLogId, $secondLogId);

        $firstLog = UserLog::query()->findOrFail($firstLogId);
        $this->assertSame(UserLog::LOGOUT_MANUAL, $firstLog->logout_reason);
    }

    public function test_model_create_logs_department_activity_in_session_window(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);
        $office = Office::factory()->create();

        $this->withSession([]);
        Auth::login($user);
        $this->actingAs($user);

        \App\Models\Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Audit Test Department',
            'code' => 'ATD',
        ]);

        $log = UserLog::query()->findOrFail((int) session('audit_user_log_id'));

        $this->assertGreaterThan(0, $log->sessionActivitiesCount());
        $this->assertStringContainsString('Department', (string) $log->sessionActivities()->value('summary'));
    }

    public function test_model_create_logs_user_activity_linked_to_session(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        Auth::login($user);

        $this->actingAs($user);

        Office::factory()->create(['name' => 'Audit Test Office']);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'user_log_id' => session('audit_user_log_id'),
            'action' => 'created',
        ]);

        $activity = UserActivityLog::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Office', $activity->summary);
    }

    public function test_close_stale_sessions_marks_session_expired(): void
    {
        config(['session.lifetime' => 120]);

        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $log = UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(3),
        ]);

        Artisan::call('sessions:close-stale');

        $log->refresh();
        $this->assertSame(UserLog::LOGOUT_SESSION_EXPIRED, $log->logout_reason);
        $this->assertNotNull($log->logged_out_at);
    }

    public function test_archive_old_logs_sets_archived_at(): void
    {
        config(['inventory.audit_log_archive_days' => 30]);

        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $log = UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now()->subDays(31),
            'last_activity_at' => now()->subDays(31),
            'logged_out_at' => now()->subDays(31),
            'logout_reason' => UserLog::LOGOUT_MANUAL,
        ]);

        UserActivityLog::query()->create([
            'user_id' => $user->id,
            'user_log_id' => $log->id,
            'action' => 'created',
            'summary' => 'Created office Audit Test',
            'created_at' => now()->subDays(31),
        ]);

        Artisan::call('audit:archive-old-logs');

        $log->refresh();
        $this->assertNotNull($log->archived_at);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_log_id' => $log->id,
        ]);

        $activity = UserActivityLog::query()->where('user_log_id', $log->id)->first();
        $this->assertNotNull($activity?->archived_at);
    }

    public function test_idle_logout_route_closes_session_with_idle_timeout_reason(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->withSession([]);
        Auth::login($user);

        $logId = (int) session('audit_user_log_id');

        $this->actingAs($user)
            ->post(route('audit.idle-logout'), [
                'redirect' => url('/'),
            ])
            ->assertRedirect(url('/'));

        $log = UserLog::query()->findOrFail($logId);
        $this->assertSame(UserLog::LOGOUT_IDLE_TIMEOUT, $log->logout_reason);
        $this->assertNotNull($log->logged_out_at);
    }
}
