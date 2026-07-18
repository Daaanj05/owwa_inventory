<?php

namespace App\Console\Commands;

use App\Services\StockPositionInactivityService;
use Illuminate\Console\Command;

class MarkStaleZeroStockPositionsInactive extends Command
{
    protected $signature = 'inventory:mark-stale-zero-stock-inactive';

    protected $description = 'Mark zero-stock positions inactive for restock after one year without stock';

    public function handle(StockPositionInactivityService $service): int
    {
        $counts = $service->reconcileStaleZeroStockPositions();

        $this->info(sprintf(
            'Scanned %d positions; aged %d; inactivated %d; cleared %d; skipped %d.',
            $counts['scanned'],
            $counts['aged'],
            $counts['inactivated'],
            $counts['cleared'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
