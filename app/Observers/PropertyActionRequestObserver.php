<?php

namespace App\Observers;

use App\Events\PropertyActionRequestChanged;
use App\Models\PropertyActionRequest;
use App\Services\ReferenceCodeService;
use App\Support\DashboardKpiCache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PropertyActionRequestObserver
{
    public function creating(PropertyActionRequest $request): void
    {
        if (empty($request->reference_code)) {
            $request->reference_code = app(ReferenceCodeService::class)->forPropertyActionRequest();
        }
    }

    public function created(PropertyActionRequest $request): void
    {
        DashboardKpiCache::bump();
        $this->broadcastPropertyActionRequestChanged($request, 'created');
    }

    public function updated(PropertyActionRequest $request): void
    {
        DashboardKpiCache::bump();
        $this->broadcastPropertyActionRequestChanged($request, 'updated');
    }

    public function deleted(PropertyActionRequest $request): void
    {
        DashboardKpiCache::bump();
    }

    public function restored(PropertyActionRequest $request): void
    {
        DashboardKpiCache::bump();
    }

    protected function broadcastPropertyActionRequestChanged(PropertyActionRequest $request, string $action): void
    {
        if (! filled(config('filament.broadcasting.echo.key'))) {
            return;
        }

        $send = function () use ($request, $action): void {
            try {
                PropertyActionRequestChanged::dispatch($request, $action);
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }
}
