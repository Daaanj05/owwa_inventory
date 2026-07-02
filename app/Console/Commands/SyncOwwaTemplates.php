<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncOwwaTemplates extends Command
{
    protected $signature = 'owwa:sync-templates {--force : Overwrite existing files in storage/app/templates}';

    protected $description = 'Copy OWWA Excel templates from resources/owwa-templates into storage/app/templates. '
        .'Annex A.4 expects a RegSPI-only master sheet; run after replacing that template.';

    public function handle(): int
    {
        $source = resource_path('owwa-templates');
        $destination = storage_path('app/templates');

        if (! is_dir($source)) {
            $this->error('Template source directory not found: '.$source);

            return self::FAILURE;
        }

        if (! $this->option('force') && count(File::allFiles($destination)) > 0) {
            $this->warn('Destination already has files. Use --force to overwrite.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($destination);

        File::copyDirectory($source, $destination);

        $count = count(File::allFiles($destination));
        $this->info("Synced {$count} template file(s) to {$destination}.");

        return self::SUCCESS;
    }
}
