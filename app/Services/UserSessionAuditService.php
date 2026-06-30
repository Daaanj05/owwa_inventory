<?php

namespace App\Services;

use App\Models\UserActivityLog;
use App\Models\UserLog;
use Illuminate\Support\Carbon;

class UserSessionAuditService
{
    public function closeOpenSessionsForUser(int $userId, string $reason, ?Carbon $endedAt = null): void
    {
        $endedAt ??= now();

        UserLog::query()
            ->where('user_id', $userId)
            ->whereNull('logged_out_at')
            ->update([
                'logged_out_at' => $endedAt,
                'logout_reason' => $reason,
            ]);
    }

    public function closeSession(UserLog $log, string $reason, ?Carbon $endedAt = null): void
    {
        if (! $log->isOpen()) {
            return;
        }

        $log->forceFill([
            'logged_out_at' => $endedAt ?? now(),
            'logout_reason' => $reason,
        ])->save();
    }

    public function touchActivity(int $userLogId): void
    {
        UserLog::query()
            ->whereKey($userLogId)
            ->whereNull('logged_out_at')
            ->update(['last_activity_at' => now()]);
    }

    public function closeStaleSessions(): int
    {
        $lifetimeMinutes = (int) config('session.lifetime', 120);
        $closed = 0;

        UserLog::query()
            ->whereNull('logged_out_at')
            ->whereNotNull('last_activity_at')
            ->orderBy('id')
            ->chunkById(100, function ($logs) use ($lifetimeMinutes, &$closed): void {
                foreach ($logs as $log) {
                    $expiresAt = $log->last_activity_at->copy()->addMinutes($lifetimeMinutes);

                    if ($expiresAt->isFuture()) {
                        continue;
                    }

                    $this->closeSession($log, UserLog::LOGOUT_SESSION_EXPIRED, $expiresAt);
                    $closed++;
                }
            });

        return $closed;
    }

    public function archiveOldLogs(): int
    {
        $days = (int) config('inventory.audit_log_archive_days', 30);
        $threshold = now()->subDays($days);
        $archivedAt = now();
        $count = 0;

        UserLog::query()
            ->whereNull('archived_at')
            ->where('logged_in_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(100, function ($logs) use ($archivedAt, &$count): void {
                $ids = $logs->pluck('id');

                UserLog::query()
                    ->whereIn('id', $ids)
                    ->update(['archived_at' => $archivedAt]);

                UserActivityLog::query()
                    ->whereIn('user_log_id', $ids)
                    ->whereNull('archived_at')
                    ->update(['archived_at' => $archivedAt]);

                $count += $ids->count();
            });

        UserActivityLog::query()
            ->whereNull('archived_at')
            ->where('created_at', '<', $threshold)
            ->update(['archived_at' => $archivedAt]);

        return $count;
    }
}
