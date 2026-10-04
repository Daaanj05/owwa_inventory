<?php

namespace App\Support;

use Filament\Facades\Filament;

class FilamentEchoShouldStart
{
    public static function forCurrentRequest(): bool
    {
        return Filament::auth()->check();
    }
}
