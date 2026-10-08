<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class CaptureSystemHealthSnapshot extends Command
{
    protected $signature = 'health:snapshot';

    protected $description = 'Capture a system health and capacity snapshot for System Admin monitoring';

    public function handle(SystemHealthService $health): int
    {
        $snapshot = $health->captureSnapshot();

        $this->info(sprintf(
            'Captured health snapshot #%d (active=%d, open=%d, checks_ok=%s).',
            $snapshot->id,
            $snapshot->active_sessions,
            $snapshot->open_sessions,
            $snapshot->checks_ok ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
