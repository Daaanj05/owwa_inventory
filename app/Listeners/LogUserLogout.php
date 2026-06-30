<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\UserLog;
use App\Services\UserSessionAuditService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Session;

class LogUserLogout
{
    public function __construct(
        protected UserSessionAuditService $sessionAudit,
    ) {}

    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $userLogId = Session::get('audit_user_log_id');

        if (! is_numeric($userLogId)) {
            return;
        }

        $log = UserLog::query()->find((int) $userLogId);

        if ($log === null) {
            return;
        }

        $reason = Session::get('audit_logout_reason', UserLog::LOGOUT_MANUAL);

        if (! is_string($reason) || $reason === '') {
            $reason = UserLog::LOGOUT_MANUAL;
        }

        $this->sessionAudit->closeSession($log, $reason);
    }
}
