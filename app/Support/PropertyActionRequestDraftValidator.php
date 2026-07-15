<?php

namespace App\Support;

use App\Models\PropertyActionRequest;
use Illuminate\Validation\ValidationException;

class PropertyActionRequestDraftValidator
{
    public static function validateReason(?string $reasonCode, ?string $reasonDetail): void
    {
        if (blank($reasonCode)) {
            throw ValidationException::withMessages([
                'reason_code' => 'Select a reason before submitting.',
            ]);
        }
    }

    public static function validateRecordReason(PropertyActionRequest $request): void
    {
        self::validateReason($request->reason_code, $request->reason_detail);
    }
}
