<?php

namespace App\Console\Commands;

use App\Services\UserSessionAuditService;
use Illuminate\Console\Command;

class CloseStaleUserSessions extends Command
{
    protected $signature = 'sessions:close-stale';

    protected $description = 'Close open user audit sessions whose server session lifetime has elapsed';

    public function handle(UserSessionAuditService $sessionAudit): int
    {
        $closed = $sessionAudit->closeStaleSessions();

        $this->info("Closed {$closed} stale user session(s).");

        return self::SUCCESS;
    }
}
