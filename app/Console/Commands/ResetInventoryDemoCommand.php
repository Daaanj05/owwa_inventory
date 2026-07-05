<?php

namespace App\Console\Commands;

use App\Support\InventoryDemoReset;
use Database\Seeders\ExportTestingDemoSeeder;
use Illuminate\Console\Command;

class ResetInventoryDemoCommand extends Command
{
    protected $signature = 'demo:reset-inventory
                            {--force : Skip confirmation prompt}
                            {--dry-run : List tables that would be truncated without making changes}';

    protected $description = 'Truncate inventory transactions and reseed export-testing demo data';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $tables = InventoryDemoReset::inventoryTables();

        if ($this->option('dry-run')) {
            $this->info('Dry run — the following tables would be truncated:');
            foreach ($tables as $table) {
                $this->line("  - {$table}");
            }
            $this->newLine();
            $this->info('Transaction reference series counters would be reset.');
            $this->info('Then ExportTestingDemoSeeder and inventory:backfill-stock-buckets would run.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will delete ALL items and inventory transactions. Users, offices, and categories are kept. Continue?',
            false,
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $this->info('Truncating inventory tables…');
        InventoryDemoReset::truncateInventoryTables();

        $this->info('Resetting transaction reference series counters…');
        InventoryDemoReset::resetTransactionReferenceSeries();

        $this->info('Seeding export-testing demo data…');
        $this->call('db:seed', ['--class' => ExportTestingDemoSeeder::class, '--force' => true]);

        $this->info('Backfilling stock buckets…');
        $this->call('inventory:backfill-stock-buckets', ['--no-interaction' => true]);

        $this->newLine();
        $this->components->info('Export demo data reset complete.');
        $this->line('Login: custodian@owwa.gov.ph / password');
        $this->line('Physical count exports: PC-EXPORT-RPCI-2026, PC-EXPORT-RPCSP-2026, PC-EXPORT-RPCPPE-2026');
        $this->line('Acquisition paperwork: DEMO-PR-CON, DEMO-PR-SEM, DEMO-PR-PPE');
        $this->newLine();
        $this->comment('Templates: run php artisan owwa:sync-templates if exports fail due to missing files.');
        $this->comment('Legacy: InventoryScenarioSeeder, ConsumptionDemoSeeder, and owwa:remove-mock-data are deprecated.');
        $this->comment('Use demo:reset-inventory for export testing instead.');

        return self::SUCCESS;
    }
}
