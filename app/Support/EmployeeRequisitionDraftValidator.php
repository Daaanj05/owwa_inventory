<?php

namespace App\Support;

use App\Models\Requisition;
use Illuminate\Validation\ValidationException;

class EmployeeRequisitionDraftValidator
{
    public static function validatePurpose(?string $purpose): void
    {
        if (blank($purpose)) {
            throw ValidationException::withMessages([
                'purpose' => 'Enter the purpose of this requisition before submitting.',
            ]);
        }
    }

    public static function validateRecordPurpose(Requisition $requisition): void
    {
        self::validatePurpose($requisition->purpose);
    }
}
