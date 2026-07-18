<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Models\Requisition;
use App\Models\User;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Livewire\Component as LivewireComponent;

class RequisitionExportActions
{
    public static function exportRisAction(): Action
    {
        return Action::make('exportRis')
            ->label('Export RIS (Appendix 63)')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (Requisition $record): bool => self::userCanExportRis() && $record->canExportRis())
            ->action(function (Requisition $record, Action $action): void {
                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    route('owwa.export.requisition', $record),
                    'Preparing Excel export…',
                    'Building your OWWA form…',
                );
            });
    }

    public static function userCanExportRis(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User
            && $user->isSupplyCustodian();
    }
}
