<?php

namespace App\Observers;

use App\Models\Disposal;
use App\Services\DisposalInventoryUnitService;
use App\Services\ReferenceCodeService;

class DisposalObserver
{
    public function creating(Disposal $disposal): void
    {
        if (empty($disposal->reference_code)) {
            $disposal->reference_code = app(ReferenceCodeService::class)->forDisposal();
        }
        if (empty($disposal->recorded_by) && auth()->check()) {
            $disposal->recorded_by = auth()->id();
        }
    }

    public function created(Disposal $disposal): void
    {
        app(DisposalInventoryUnitService::class)->markUnitDisposed($disposal);
    }
}
