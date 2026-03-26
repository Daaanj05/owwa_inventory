<?php

namespace App\Console\Commands;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Models\Item;
use Illuminate\Console\Command;

class RemoveMockDataCommand extends Command
{
    protected $signature = 'owwa:remove-mock-data
                            {--dry-run : List what would be removed without deleting}';

    protected $description = 'Remove demo/mock inventory data from the database (from InventoryScenarioSeeder or similar).';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no data will be deleted.');
        }

        // 1. Demo issuances (reference_code like ISS-DEMO-%)
        $issuances = Issuance::query()->where('reference_code', 'like', 'ISS-DEMO-%')->get();
        $this->info('Issuances with reference ISS-DEMO-*: '.$issuances->count());
        if ($issuances->isNotEmpty() && ! $dryRun) {
            Issuance::query()->where('reference_code', 'like', 'ISS-DEMO-%')->delete();
            $this->info('Deleted '.$issuances->count().' demo issuance(s).');
        }

        // 2. Demo acquisitions (reference_code like ACQ-DEMO-% or source = 'Demo seed')
        $acquisitions = Acquisition::query()
            ->where(function ($q) {
                $q->where('reference_code', 'like', 'ACQ-DEMO-%')
                    ->orWhere('source', 'Demo seed');
            })
            ->get();
        $this->info('Acquisitions (ACQ-DEMO-* or source "Demo seed"): '.$acquisitions->count());
        if ($acquisitions->isNotEmpty() && ! $dryRun) {
            Acquisition::query()
                ->where(function ($q) {
                    $q->where('reference_code', 'like', 'ACQ-DEMO-%')
                        ->orWhere('source', 'Demo seed');
                })
                ->delete();
            $this->info('Deleted '.$acquisitions->count().' demo acquisition(s).');
        }

        // 3. Demo items (description from InventoryScenarioSeeder)
        $items = Item::query()
            ->where('description', 'Demo item for AI procurement scenarios')
            ->get();
        $this->info('Items with demo description: '.$items->count());
        if ($items->isNotEmpty() && ! $dryRun) {
            foreach ($items as $item) {
                if ($item->acquisitions()->exists() || $item->issuances()->exists()) {
                    $this->warn("Item [{$item->item_code}] still has acquisitions/issuances; skipped.");

                    continue;
                }
                $item->delete();
            }
            $this->info('Deleted demo item(s) with no remaining transactions.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Run without --dry-run to remove the data: php artisan owwa:remove-mock-data');
        } else {
            $this->newLine();
            $this->info('Done. Mock/demo data has been removed.');
        }

        return self::SUCCESS;
    }
}
