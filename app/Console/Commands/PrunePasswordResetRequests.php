<?php

namespace App\Console\Commands;

use App\Services\PasswordResetRequestService;
use Illuminate\Console\Command;

class PrunePasswordResetRequests extends Command
{
    protected $signature = 'password-reset-requests:prune';

    protected $description = 'Delete password reset request records older than the configured retention period';

    public function handle(PasswordResetRequestService $service): int
    {
        $days = (int) config('inventory.password_reset_request_retention_days', 30);
        $deleted = $service->pruneExpired();

        $this->info("Deleted {$deleted} password reset request(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
