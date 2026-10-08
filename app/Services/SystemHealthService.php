<?php

namespace App\Services;

use App\Models\SystemHealthSnapshot;
use App\Models\User;
use App\Models\UserLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    /**
     * @return array{
     *     checks: list<array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}>,
     *     capacity: array{
     *         open_sessions: int,
     *         active_sessions: int,
     *         active_window_minutes: int,
     *         laravel_sessions: int|null,
     *         pending_jobs: int|null,
     *         failed_jobs_hour: int|null,
     *         by_role: array<string, int>,
     *         by_panel: array<string, int>
     *     },
     *     history: array{
     *         peak_active_today: int,
     *         peak_active_7d: int,
     *         last_snapshot_at: string|null
     *     }
     * }
     */
    public function report(): array
    {
        $checks = $this->runChecks();
        $capacity = $this->capacityMetrics();

        return [
            'checks' => $checks,
            'capacity' => $capacity,
            'history' => $this->historyMetrics(),
            'online' => $this->onlinePeopleOverview(),
        ];
    }

    /**
     * People currently signed in, for the status overview modal.
     *
     * @return array{
     *     active_window_minutes: int,
     *     active: list<array{name: string, role: string, portal: string, last_activity: string, is_active: bool}>,
     *     idle: list<array{name: string, role: string, portal: string, last_activity: string, is_active: bool}>,
     *     by_role: array<string, int>,
     *     by_portal: array<string, int>
     * }
     */
    public function onlinePeopleOverview(): array
    {
        $windowMinutes = (int) config('inventory.health_active_session_minutes', 15);
        $activeSince = now()->subMinutes($windowMinutes);
        $openSince = $this->sessionOpenSince();

        $logs = UserLog::query()
            ->with('user')
            ->whereNull('logged_out_at')
            ->where('last_activity_at', '>=', $openSince)
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();

        $active = [];
        $idle = [];

        foreach ($logs as $log) {
            $user = $log->user;
            $row = [
                'name' => $user?->name ?: ('User #'.$log->user_id),
                'role' => self::roleLabel((string) ($user?->role ?? 'unknown')),
                'portal' => self::panelLabel((string) ($log->panel ?? 'unknown')),
                'last_activity' => $log->last_activity_at?->diffForHumans() ?? '—',
                'is_active' => $log->last_activity_at !== null && $log->last_activity_at->gte($activeSince),
            ];

            if ($row['is_active']) {
                $active[] = $row;
            } else {
                $idle[] = $row;
            }
        }

        return [
            'active_window_minutes' => $windowMinutes,
            'active' => $active,
            'idle' => $idle,
            'by_role' => collect($active)
                ->countBy('role')
                ->all(),
            'by_portal' => collect($active)
                ->countBy('portal')
                ->all(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}>
     */
    public function runChecks(): array
    {
        return [
            $this->applicationCheck(),
            $this->databaseCheck(),
            $this->cacheCheck(),
            $this->sessionStoreCheck(),
            $this->queueCheck(),
            $this->mailerCheck(),
            $this->storageCheck(),
            $this->scheduleHeartbeatCheck(),
        ];
    }

    public function captureSnapshot(): SystemHealthSnapshot
    {
        $checks = $this->runChecks();
        $capacity = $this->capacityMetrics();
        $checksOk = collect($checks)->every(fn (array $check): bool => $check['status'] !== 'fail');

        $snapshot = SystemHealthSnapshot::query()->create([
            'captured_at' => now(),
            'open_sessions' => $capacity['open_sessions'],
            'active_sessions' => $capacity['active_sessions'],
            'laravel_sessions' => $capacity['laravel_sessions'],
            'pending_jobs' => $capacity['pending_jobs'],
            'failed_jobs' => $capacity['failed_jobs_hour'],
            'checks_ok' => $checksOk,
            'checks_json' => collect($checks)
                ->mapWithKeys(fn (array $check): array => [
                    $check['key'] => [
                        'status' => $check['status'],
                        'message' => $check['message'],
                        'evidence' => $check['evidence'],
                    ],
                ])
                ->all(),
        ]);

        $this->pruneOldSnapshots();

        return $snapshot;
    }

    public function pruneOldSnapshots(): int
    {
        $days = (int) config('inventory.health_snapshot_retention_days', 7);

        return SystemHealthSnapshot::query()
            ->where('captured_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * @return array{
     *     open_sessions: int,
     *     active_sessions: int,
     *     active_window_minutes: int,
     *     laravel_sessions: int|null,
     *     pending_jobs: int|null,
     *     failed_jobs_hour: int|null,
     *     by_role: array<string, int>,
     *     by_panel: array<string, int>
     * }
     */
    public function capacityMetrics(): array
    {
        $windowMinutes = (int) config('inventory.health_active_session_minutes', 15);
        $activeSince = now()->subMinutes($windowMinutes);
        $openSince = $this->sessionOpenSince();

        $openSessions = UserLog::query()
            ->whereNull('logged_out_at')
            ->where('last_activity_at', '>=', $openSince)
            ->count();
        $activeSessions = UserLog::query()
            ->whereNull('logged_out_at')
            ->where('last_activity_at', '>=', $activeSince)
            ->count();

        $byRole = UserLog::query()
            ->whereNull('user_logs.logged_out_at')
            ->where('user_logs.last_activity_at', '>=', $activeSince)
            ->join('users', 'users.id', '=', 'user_logs.user_id')
            ->selectRaw('users.role, count(*) as total')
            ->groupBy('users.role')
            ->pluck('total', 'role')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $byPanel = UserLog::query()
            ->whereNull('logged_out_at')
            ->where('last_activity_at', '>=', $activeSince)
            ->selectRaw("coalesce(panel, 'unknown') as panel_key, count(*) as total")
            ->groupBy('panel_key')
            ->pluck('total', 'panel_key')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return [
            'open_sessions' => $openSessions,
            'active_sessions' => $activeSessions,
            'active_window_minutes' => $windowMinutes,
            'laravel_sessions' => $this->laravelSessionCount(),
            'pending_jobs' => $this->pendingJobsCount(),
            'failed_jobs_hour' => $this->failedJobsLastHourCount(),
            'by_role' => $byRole,
            'by_panel' => $byPanel,
        ];
    }

    /**
     * Open audit sessions older than the Laravel session lifetime are treated as expired
     * (same cutoff as UserSessionAuditService::closeStaleSessions).
     */
    protected function sessionOpenSince(): Carbon
    {
        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        return now()->subMinutes($lifetimeMinutes);
    }

    /**
     * @return array{peak_active_today: int, peak_active_7d: int, last_snapshot_at: string|null}
     */
    public function historyMetrics(): array
    {
        if (! Schema::hasTable('system_health_snapshots')) {
            return [
                'peak_active_today' => 0,
                'peak_active_7d' => 0,
                'last_snapshot_at' => null,
            ];
        }

        $last = SystemHealthSnapshot::query()->latest('captured_at')->first();

        return [
            'peak_active_today' => (int) (SystemHealthSnapshot::query()
                ->where('captured_at', '>=', now()->startOfDay())
                ->max('active_sessions') ?? 0),
            'peak_active_7d' => (int) (SystemHealthSnapshot::query()
                ->where('captured_at', '>=', now()->subDays(7))
                ->max('active_sessions') ?? 0),
            'last_snapshot_at' => $last?->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function check(
        string $key,
        string $label,
        string $status,
        string $message,
        string $evidence,
        string $detail,
        mixed $metric = null,
        ?string $href = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'evidence' => $evidence,
            'detail' => $detail,
            'href' => $href,
            'metric' => $metric,
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function applicationCheck(): array
    {
        $env = (string) config('app.env');

        return $this->check(
            key: 'application',
            label: 'Application',
            status: 'ok',
            message: 'Application booted successfully.',
            evidence: 'Environment: '.ucfirst($env),
            detail: 'The app started normally and the /up health page is available.',
            metric: $env,
        );
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function databaseCheck(): array
    {
        $connection = (string) config('database.default');

        try {
            DB::select('select 1');

            return $this->check(
                key: 'database',
                label: 'Database',
                status: 'ok',
                message: 'Database connection is healthy.',
                evidence: 'Database: '.strtoupper($connection),
                detail: 'Connected successfully (quick test query).',
                metric: $connection,
            );
        } catch (Throwable $exception) {
            return $this->check(
                key: 'database',
                label: 'Database',
                status: 'fail',
                message: 'Database connection failed: '.$exception->getMessage(),
                evidence: 'Database: '.strtoupper($connection),
                detail: 'Could not run a quick test query.',
                metric: null,
            );
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function cacheCheck(): array
    {
        $store = (string) config('cache.default');

        try {
            $key = 'system-health:'.uniqid('', true);
            Cache::put($key, 'ok', 10);
            $value = Cache::pull($key);

            if ($value !== 'ok') {
                return $this->check(
                    key: 'cache',
                    label: 'Cache',
                    status: 'fail',
                    message: 'Cache round-trip did not return the expected value.',
                    evidence: 'Cache: '.ucfirst($store),
                    detail: 'Wrote and read a test value — result did not match.',
                    metric: $store,
                );
            }

            return $this->check(
                key: 'cache',
                label: 'Cache',
                status: 'ok',
                message: 'Cache store is readable and writable.',
                evidence: 'Cache: '.ucfirst($store),
                detail: 'Wrote and read a test value successfully.',
                metric: $store,
            );
        } catch (Throwable $exception) {
            return $this->check(
                key: 'cache',
                label: 'Cache',
                status: 'fail',
                message: 'Cache check failed: '.$exception->getMessage(),
                evidence: 'Cache: '.ucfirst($store),
                detail: 'Could not write or read a test value.',
                metric: $store,
            );
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function sessionStoreCheck(): array
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            return $this->check(
                key: 'session',
                label: 'Session store',
                status: 'ok',
                message: 'Session driver is '.$driver.'.',
                evidence: 'Sign-in storage: '.ucfirst($driver),
                detail: 'Sessions are stored with the '.$driver.' driver (no row count).',
                metric: $driver,
            );
        }

        try {
            if (! Schema::hasTable('sessions')) {
                return $this->check(
                    key: 'session',
                    label: 'Session store',
                    status: 'fail',
                    message: 'Sessions table is missing.',
                    evidence: 'Sign-in storage: Database',
                    detail: 'Expected sessions table was not found.',
                    metric: $driver,
                );
            }

            $count = DB::table('sessions')->count();

            return $this->check(
                key: 'session',
                label: 'Session store',
                status: 'ok',
                message: 'Database sessions table is readable.',
                evidence: 'Sign-in storage: Database · '.$count.' rows',
                detail: 'Counted saved browser sign-in rows.',
                metric: $count,
            );
        } catch (Throwable $exception) {
            return $this->check(
                key: 'session',
                label: 'Session store',
                status: 'fail',
                message: 'Session store check failed: '.$exception->getMessage(),
                evidence: 'Sign-in storage: '.ucfirst($driver),
                detail: 'Could not read saved sign-in rows.',
                metric: $driver,
            );
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function queueCheck(): array
    {
        $pending = $this->pendingJobsCount();
        $failed = $this->failedJobsLastHourCount();
        $connection = (string) config('queue.default');

        if ($pending === null && $failed === null) {
            return $this->check(
                key: 'queue',
                label: 'Queue',
                status: 'warn',
                message: 'Queue tables are unavailable for the current connection.',
                evidence: 'Background jobs: unavailable',
                detail: 'Could not read the job waiting list.',
                metric: $connection,
            );
        }

        $evidence = sprintf(
            'Waiting: %d · Failed (1h): %d',
            $pending ?? 0,
            $failed ?? 0,
        );

        if (($failed ?? 0) > 0) {
            return $this->check(
                key: 'queue',
                label: 'Queue',
                status: 'warn',
                message: "{$failed} failed job(s) in the last hour; {$pending} pending.",
                evidence: $evidence,
                detail: 'Checked waiting jobs and failures from the last hour.',
                metric: [
                    'pending' => $pending,
                    'failed_hour' => $failed,
                ],
            );
        }

        return $this->check(
            key: 'queue',
            label: 'Queue',
            status: 'ok',
            message: ($pending ?? 0).' pending job(s); no failures in the last hour.',
            evidence: $evidence,
            detail: 'Checked waiting jobs and failures from the last hour.',
            metric: [
                'pending' => $pending,
                'failed_hour' => $failed,
            ],
        );
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function mailerCheck(): array
    {
        $mailer = (string) config('mail.default');

        if ($mailer === '') {
            return $this->check(
                key: 'mailer',
                label: 'Mailer',
                status: 'warn',
                message: 'Mailer is not configured.',
                evidence: 'Mail: not set',
                detail: 'No mail method is configured.',
                metric: null,
            );
        }

        if ($mailer === 'log') {
            return $this->check(
                key: 'mailer',
                label: 'Mailer',
                status: 'ok',
                message: 'Mail logged locally — not sent externally.',
                evidence: 'Mail: Log only (not emailed)',
                detail: 'Messages are written to the app log instead of being emailed.',
                metric: $mailer,
            );
        }

        return $this->check(
            key: 'mailer',
            label: 'Mailer',
            status: 'ok',
            message: 'Mailer is configured as '.$mailer.'.',
            evidence: 'Mail: '.ucfirst($mailer),
            detail: 'Using the configured email method.',
            metric: $mailer,
        );
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function storageCheck(): array
    {
        $paths = [
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                return $this->check(
                    key: 'storage',
                    label: 'Storage',
                    status: 'fail',
                    message: 'Path is missing or not writable: '.$path,
                    evidence: 'Disk: blocked',
                    detail: 'A required folder is missing or not writable.',
                    metric: $path,
                );
            }
        }

        return $this->check(
            key: 'storage',
            label: 'Storage',
            status: 'ok',
            message: 'Application storage paths are writable.',
            evidence: 'Disk: '.count($paths).' folders OK',
            detail: 'Checked app storage, logs, and cache folders.',
            metric: count($paths),
        );
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}
     */
    protected function scheduleHeartbeatCheck(): array
    {
        $detail = 'Scheduler heartbeat every 15 min — proves cron is running.';

        if (! Schema::hasTable('system_health_snapshots')) {
            return $this->check(
                key: 'schedule',
                label: 'Schedule heartbeat',
                status: 'warn',
                message: 'No snapshots yet. Capture one now, or wait for the 15-minute scheduler.',
                evidence: 'Last check: none yet',
                detail: $detail,
                metric: null,
            );
        }

        $last = SystemHealthSnapshot::query()->latest('captured_at')->first();

        if ($last === null) {
            return $this->check(
                key: 'schedule',
                label: 'Schedule heartbeat',
                status: 'warn',
                message: 'No snapshots yet. Capture one now, or wait for the 15-minute scheduler.',
                evidence: 'Last check: none yet',
                detail: $detail,
                metric: null,
            );
        }

        $ageMinutes = (int) $last->captured_at->diffInMinutes(now());
        $staleAfter = (int) config('inventory.health_snapshot_stale_minutes', 45);
        $evidence = 'Last check: '.$ageMinutes.' min ago';

        if ($ageMinutes > $staleAfter) {
            return $this->check(
                key: 'schedule',
                label: 'Schedule heartbeat',
                status: 'warn',
                message: "Last snapshot was {$ageMinutes} minute(s) ago.",
                evidence: $evidence,
                detail: $detail,
                metric: $last->captured_at->toIso8601String(),
            );
        }

        return $this->check(
            key: 'schedule',
            label: 'Schedule heartbeat',
            status: 'ok',
            message: 'Last snapshot '.$last->captured_at->diffForHumans().'.',
            evidence: $evidence,
            detail: $detail,
            metric: $last->captured_at->toIso8601String(),
        );
    }

    protected function laravelSessionCount(): ?int
    {
        if ((string) config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return null;
        }

        try {
            return (int) DB::table('sessions')->count();
        } catch (Throwable) {
            return null;
        }
    }

    protected function pendingJobsCount(): ?int
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        try {
            return (int) DB::table('jobs')->count();
        } catch (Throwable) {
            return null;
        }
    }

    protected function failedJobsLastHourCount(): ?int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        try {
            return (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            User::ROLE_SYSTEM_ADMIN => 'System admin',
            User::ROLE_SUPPLY_CUSTODIAN => 'Supply custodian',
            User::ROLE_UNIT_CONSOLIDATOR => 'Unit consolidator',
            User::ROLE_EMPLOYEE => 'Employee',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }

    public static function panelLabel(string $panel): string
    {
        return match ($panel) {
            'system-admin' => 'System Admin',
            'admin' => 'Operations',
            'unknown', '' => 'Unknown',
            default => ucfirst(str_replace(['-', '_'], ' ', $panel)),
        };
    }
}
