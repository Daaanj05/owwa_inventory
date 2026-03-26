<?php

namespace App\Observers;

use App\Models\Acquisition;
use App\Services\ReferenceCodeService;

class AcquisitionObserver
{
    public function creating(Acquisition $acquisition): void
    {
        if (empty($acquisition->reference_code)) {
            $acquisition->reference_code = app(ReferenceCodeService::class)->forAcquisition();
        }
        if (empty($acquisition->recorded_by) && auth()->check()) {
            $acquisition->recorded_by = auth()->id();
        }
    }
}
