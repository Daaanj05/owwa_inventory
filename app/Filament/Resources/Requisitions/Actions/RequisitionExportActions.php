<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Models\Requisition;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class RequisitionExportActions
{
    public static function exportRisAction(): Action
    {
        return Action::make('exportRis')
            ->label('Export RIS (Appendix 63)')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (Requisition $record): bool => self::userCanExportRis() && $record->canExportRis())
            ->action(function (Requisition $record) {
                return Redirect::away(route('owwa.export.requisition', $record));
            });
    }

    public static function userCanExportRis(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User
            && $user->isSupplyCustodian();
    }
}
