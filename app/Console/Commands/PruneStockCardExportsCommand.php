<?php

namespace App\Console\Commands;

use App\Services\StockCardQueuedExportService;
use Illuminate\Console\Command;

class PruneStockCardExportsCommand extends Command
{
    protected $signature = 'owwa:prune-stock-card-exports {--hours=24 : Delete files older than this many hours}';

    protected $description = 'Delete expired background stock card export files';

    public function handle(StockCardQueuedExportService $exportService): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = $exportService->pruneOlderThanHours($hours);
        $this->info("Deleted {$deleted} expired stock card export file(s).");

        return self::SUCCESS;
    }
}
