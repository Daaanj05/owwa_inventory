<?php

namespace App\Observers;

use App\Models\Issuance;
use App\Services\ReferenceCodeService;

class IssuanceObserver
{
    public function creating(Issuance $issuance): void
    {
        if (empty($issuance->reference_code)) {
            $issuance->reference_code = app(ReferenceCodeService::class)->forIssuance();
        }
        if (empty($issuance->issued_by) && auth()->check()) {
            $issuance->issued_by = auth()->id();
        }
    }
}
