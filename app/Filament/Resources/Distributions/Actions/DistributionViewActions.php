<?php

namespace App\Filament\Resources\Distributions\Actions;

use App\Models\Distribution;
use App\Models\User;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Livewire\Component as LivewireComponent;

class DistributionViewActions
{
    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA form')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (): bool => Auth::user() instanceof User && Auth::user()->isSupplyCustodian())
            ->action(function (Distribution $record, Action $action): void {
                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    route('owwa.export.distribution', $record),
                    'Preparing Excel export…',
                    'Building your OWWA form…',
                );
            });
    }
}
