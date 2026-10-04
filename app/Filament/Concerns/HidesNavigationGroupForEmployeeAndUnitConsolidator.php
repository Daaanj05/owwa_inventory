<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use UnitEnum;

trait HidesNavigationGroupForEmployeeAndUnitConsolidator
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && ($user->isEmployee() || $user->isUnitConsolidator())) {
            return null;
        }

        return static::$navigationGroup;
    }
}
