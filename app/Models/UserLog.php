<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class UserLog extends Model
{
    public const LOGOUT_MANUAL = 'manual';

    public const LOGOUT_IDLE_TIMEOUT = 'idle_timeout';

    public const LOGOUT_SESSION_EXPIRED = 'session_expired';

    public const LOGOUT_NEW_LOGIN = 'new_login';

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'path',
        'panel',
        'logged_in_at',
        'logged_out_at',
        'logout_reason',
        'last_activity_at',
        'session_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(UserActivityLog::class);
    }

    public function isOpen(): bool
    {
        return $this->logged_out_at === null;
    }

    public function sessionEndedAt(): Carbon
    {
        if ($this->logged_out_at !== null) {
            return $this->logged_out_at;
        }

        return now();
    }

    /**
     * @return Builder<UserActivityLog>
     */
    public function sessionActivities(): Builder
    {
        $endedAt = $this->sessionEndedAt();

        return UserActivityLog::query()
            ->where('user_id', $this->user_id)
            ->where(function (Builder $query) use ($endedAt): void {
                $query->where('user_log_id', $this->id);

                if ($this->logged_in_at !== null) {
                    $query->orWhereBetween('created_at', [$this->logged_in_at, $endedAt]);
                }
            })
            ->orderByDesc('created_at');
    }

    public function sessionActivitiesCount(): int
    {
        return $this->sessionActivities()->count();
    }

    public function sessionDurationLabel(): string
    {
        if ($this->logged_in_at === null) {
            return '—';
        }

        $minutes = (int) round($this->logged_in_at->diffInMinutes($this->sessionEndedAt()));

        if ($minutes < 1) {
            return 'Less than 1 min';
        }

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($remainder === 0) {
            return "{$hours} hr";
        }

        return "{$hours} hr {$remainder} min";
    }

    public static function logoutReasonLabel(?string $reason): string
    {
        return match ($reason) {
            self::LOGOUT_MANUAL => 'Manual logout',
            self::LOGOUT_IDLE_TIMEOUT => 'Idle timeout',
            self::LOGOUT_SESSION_EXPIRED => 'Session expired',
            self::LOGOUT_NEW_LOGIN => 'New login',
            default => $reason !== null && $reason !== '' ? ucfirst(str_replace('_', ' ', $reason)) : 'Active',
        };
    }
}
