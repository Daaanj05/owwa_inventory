<?php

namespace App\Observers;

use App\Models\Requisition;
use App\Services\ReferenceCodeService;

class RequisitionObserver
{
    public function creating(Requisition $requisition): void
    {
        if (empty($requisition->reference_code)) {
            $requisition->reference_code = app(ReferenceCodeService::class)->forRequisition();
        }
        if (empty($requisition->requested_by) && auth()->check()) {
            $requisition->requested_by = auth()->id();
        }
    }
}
