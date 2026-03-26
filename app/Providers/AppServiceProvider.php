<?php

namespace App\Providers;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Observers\AcquisitionObserver;
use App\Observers\DisposalObserver;
use App\Observers\IssuanceObserver;
use App\Observers\RequisitionObserver;
use App\Observers\TransferObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Acquisition::observe(AcquisitionObserver::class);
        Issuance::observe(IssuanceObserver::class);
        Transfer::observe(TransferObserver::class);
        Disposal::observe(DisposalObserver::class);
        Requisition::observe(RequisitionObserver::class);
    }
}
