<?php

namespace App\Filament\Resources\Distributions\Actions;

use App\Models\Distribution;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Redirect;

class DistributionViewActions
{
    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA form')
            ->icon('heroicon-o-document-arrow-down')
            ->action(fn (Distribution $record) => Redirect::away(route('owwa.export.distribution', $record)));
    }
}
