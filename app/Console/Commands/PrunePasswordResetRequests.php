<?php

namespace App\Console\Commands;

use App\Services\PasswordResetCodeService;
use Illuminate\Console\Command;

class PrunePasswordResetRequests extends Command
{
    protected $signature = 'password-reset-requests:prune';

    protected $description = 'Delete password reset request records older than the configured retention period';

    public function handle(PasswordResetCodeService $service): int
    {
        $days = (int) config('inventory.password_reset_request_retention_days', 30);
        $deleted = $service->pruneExpiredRequests();

        $this->info("Deleted {$deleted} password reset request(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
