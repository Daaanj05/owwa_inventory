<?php

namespace App\Observers;

use App\Models\PropertyActionRequest;
use App\Services\ReferenceCodeService;

class PropertyActionRequestObserver
{
    public function creating(PropertyActionRequest $request): void
    {
        if (empty($request->reference_code)) {
            $request->reference_code = app(ReferenceCodeService::class)->forPropertyActionRequest();
        }
    }
}
