<?php

namespace App\Support;

class PasswordRules
{
    public static function helperText(): string
    {
        return 'At least 8 characters with one uppercase letter, one lowercase letter, and one number.';
    }
}
