<?php

namespace App\Providers;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\PhysicalCountSession;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Observers\AcquisitionObserver;
use App\Observers\DisposalObserver;
use App\Observers\IssuanceObserver;
use App\Observers\ItemObserver;
use App\Observers\PhysicalCountSessionObserver;
use App\Observers\RequisitionObserver;
use App\Observers\TransferObserver;
use App\Support\RetryingFilesystem;
use Filament\Tables\Table;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('files', function () {
            return new RetryingFilesystem;
        });

        $this->app->alias('files', Filesystem::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(8)
            ->letters()
            ->mixedCase()
            ->numbers());

        Table::configureUsing(fn (Table $table): Table => $table
            ->paginationPageOptions([10])
            ->defaultPaginationPageOption(10));

        Acquisition::observe(AcquisitionObserver::class);
        Item::observe(ItemObserver::class);
        Issuance::observe(IssuanceObserver::class);
        Transfer::observe(TransferObserver::class);
        Disposal::observe(DisposalObserver::class);
        Requisition::observe(RequisitionObserver::class);
        PhysicalCountSession::observe(PhysicalCountSessionObserver::class);

        if (! class_exists(\ZipArchive::class)) {
            Log::warning('PHP ext-zip is not loaded. OWWA Excel (.xlsx) exports will fail until extension=zip is enabled in the web server php.ini.');
        }

        if (app()->isLocal()) {
            DB::listen(function (QueryExecuted $query): void {
                $thresholdMs = (int) (env('SLOW_QUERY_MS', 200));

                if ($query->time < $thresholdMs) {
                    return;
                }

                Log::warning('Slow query detected.', [
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                ]);
            });
        }
    }
}
