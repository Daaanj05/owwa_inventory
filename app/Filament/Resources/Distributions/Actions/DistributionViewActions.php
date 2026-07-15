<?php

namespace App\Filament\Resources\Distributions\Actions;

use App\Models\Distribution;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class DistributionViewActions
{
    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA form')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (): bool => Auth::user() instanceof User && Auth::user()->isSupplyCustodian())
            ->action(fn (Distribution $record) => Redirect::away(route('owwa.export.distribution', $record)));
    }
}
