<?php

namespace Tests\Feature;

use App\Models\SystemHealthSnapshot;
use App\Models\User;
use App\Models\UserLog;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_ok_mailer_when_using_log_driver(): void
    {
        config(['mail.default' => 'log']);

        $report = app(SystemHealthService::class)->report();
        $mailer = collect($report['checks'])->firstWhere('key', 'mailer');

        $this->assertSame('ok', $mailer['status']);
        $this->assertStringContainsString('logged locally', $mailer['message']);
        $this->assertSame('Mail: Log only (not emailed)', $mailer['evidence']);
        $this->assertNotSame('', $mailer['detail']);
    }

    public function test_online_people_overview_lists_active_sessions(): void
    {
        config(['inventory.health_active_session_minutes' => 15]);

        $user = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'name' => 'Ada Admin',
            'email_verified_at' => now(),
        ]);

        UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/system-admin',
            'panel' => 'system-admin',
            'logged_in_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
        ]);

        $online = app(SystemHealthService::class)->onlinePeopleOverview();

        $this->assertCount(1, $online['active']);
        $this->assertSame('Ada Admin', $online['active'][0]['name']);
        $this->assertSame('System Admin', $online['active'][0]['portal']);
        $this->assertSame(1, $online['by_role']['System admin'] ?? 0);
    }

    public function test_every_check_includes_evidence_and_detail(): void
    {
        $checks = app(SystemHealthService::class)->runChecks();

        $this->assertNotEmpty($checks);

        foreach ($checks as $check) {
            $this->assertNotSame('', $check['evidence'] ?? '');
            $this->assertNotSame('', $check['detail'] ?? '');
            $this->assertArrayHasKey('href', $check);
        }
    }

    public function test_capacity_counts_open_and_recently_active_sessions(): void
    {
        config([
            'inventory.health_active_session_minutes' => 15,
            'session.lifetime' => 120,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/login',
            'panel' => 'admin',
            'logged_in_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(2),
        ]);

        UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/login',
            'panel' => 'admin',
            'logged_in_at' => now()->subHours(3),
            'last_activity_at' => now()->subMinutes(45),
        ]);

        UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/login',
            'panel' => 'admin',
            'logged_in_at' => now()->subMonths(2),
            'last_activity_at' => now()->subMonths(2),
        ]);

        $capacity = app(SystemHealthService::class)->capacityMetrics();
        $online = app(SystemHealthService::class)->onlinePeopleOverview();

        $this->assertSame(2, $capacity['open_sessions']);
        $this->assertSame(1, $capacity['active_sessions']);
        $this->assertSame(1, $capacity['by_role'][User::ROLE_EMPLOYEE] ?? 0);
        $this->assertCount(1, $online['active']);
        $this->assertCount(1, $online['idle']);
    }

    public function test_capacity_and_overview_exclude_sessions_past_lifetime(): void
    {
        config([
            'inventory.health_active_session_minutes' => 15,
            'session.lifetime' => 120,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Prof. Rebekah Vandervort MD',
            'email_verified_at' => now(),
        ]);

        UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/admin',
            'panel' => 'admin',
            'logged_in_at' => now()->subMonths(2),
            'last_activity_at' => now()->subMonths(2),
        ]);

        $capacity = app(SystemHealthService::class)->capacityMetrics();
        $online = app(SystemHealthService::class)->onlinePeopleOverview();

        $this->assertSame(0, $capacity['open_sessions']);
        $this->assertSame(0, $capacity['active_sessions']);
        $this->assertSame([], $online['active']);
        $this->assertSame([], $online['idle']);
    }

    public function test_capture_snapshot_persists_metrics_and_prunes_old_rows(): void
    {
        config(['inventory.health_snapshot_retention_days' => 7]);

        SystemHealthSnapshot::query()->create([
            'captured_at' => now()->subDays(10),
            'open_sessions' => 1,
            'active_sessions' => 1,
            'checks_ok' => true,
            'checks_json' => [],
        ]);

        $snapshot = app(SystemHealthService::class)->captureSnapshot();

        $this->assertDatabaseHas('system_health_snapshots', [
            'id' => $snapshot->id,
            'checks_ok' => true,
        ]);
        $this->assertSame(1, SystemHealthSnapshot::query()->count());
        $this->assertArrayHasKey('evidence', $snapshot->checks_json['mailer'] ?? []);
    }
}
