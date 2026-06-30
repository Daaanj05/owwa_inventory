<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\UserLog;
use App\Services\UserSessionAuditService;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Session;

class LogUserLogin
{
    public function __construct(
        protected UserSessionAuditService $sessionAudit,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $userLogId = Session::get('audit_user_log_id');

        if (is_numeric($userLogId)) {
            $existing = UserLog::query()->find((int) $userLogId);

            if ($existing !== null
                && $existing->isOpen()
                && $existing->user_id === $user->id) {
                return;
            }
        }

        $this->sessionAudit->closeOpenSessionsForUser($user->id, UserLog::LOGOUT_NEW_LOGIN);

        $now = now();

        $log = UserLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
            'path' => request()->path(),
            'panel' => Filament::getCurrentPanel()?->getId(),
            'logged_in_at' => $now,
            'last_activity_at' => $now,
            'session_id' => Session::getId(),
        ]);

        Session::put('audit_user_log_id', $log->id);
    }
}
