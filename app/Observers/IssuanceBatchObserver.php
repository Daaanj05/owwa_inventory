<?php

namespace App\Observers;

use App\Models\IssuanceBatch;
use App\Services\ReferenceCodeService;

class IssuanceBatchObserver
{
    public function creating(IssuanceBatch $batch): void
    {
        if (blank($batch->reference_code)) {
            $batch->reference_code = app(ReferenceCodeService::class)
                ->forIssuanceBatch($batch->category_slug);
        }

        if (empty($batch->issued_by) && auth()->check()) {
            $batch->issued_by = auth()->id();
        }
    }
}
