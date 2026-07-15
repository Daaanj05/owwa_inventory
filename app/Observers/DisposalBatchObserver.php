<?php

namespace App\Observers;

use App\Models\DisposalBatch;
use App\Services\ReferenceCodeService;

class DisposalBatchObserver
{
    public function creating(DisposalBatch $batch): void
    {
        if (blank($batch->reference_code)) {
            $batch->reference_code = app(ReferenceCodeService::class)
                ->forDisposalBatch($batch->category_slug);
        }

        if (empty($batch->recorded_by) && auth()->check()) {
            $batch->recorded_by = auth()->id();
        }
    }
}
