<?php

namespace App\Console\Commands;

use App\Services\UserSessionAuditService;
use Illuminate\Console\Command;

class ArchiveOldAuditLogs extends Command
{
    protected $signature = 'audit:archive-old-logs';

    protected $description = 'Archive login and activity audit logs older than the configured retention period';

    public function handle(UserSessionAuditService $sessionAudit): int
    {
        $days = (int) config('inventory.audit_log_archive_days', 30);
        $archived = $sessionAudit->archiveOldLogs();

        $this->info("Archived {$archived} login session(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
