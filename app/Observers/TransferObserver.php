<?php

namespace App\Observers;

use App\Models\Transfer;
use App\Services\PropertyReturnService;
use App\Services\ReferenceCodeService;

class TransferObserver
{
    public function creating(Transfer $transfer): void
    {
        if (empty($transfer->reference_code)) {
            $transfer->reference_code = app(ReferenceCodeService::class)->forTransfer();
        }
        if (empty($transfer->recorded_by) && auth()->check()) {
            $transfer->recorded_by = auth()->id();
        }
    }

    public function created(Transfer $transfer): void
    {
        app(PropertyReturnService::class)->processReturnTransfer($transfer);
    }
}
