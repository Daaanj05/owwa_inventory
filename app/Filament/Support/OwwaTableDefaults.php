<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Tables\Table;

class OwwaTableDefaults
{
    public static function hideRedundantToolbarIcons(Table $table): Table
    {
        return $table
            ->columnManager(false)
            ->filtersTriggerAction(fn (Action $action) => $action->visible(false))
            ->columnManagerTriggerAction(fn (Action $action) => $action->visible(false));
    }
}
