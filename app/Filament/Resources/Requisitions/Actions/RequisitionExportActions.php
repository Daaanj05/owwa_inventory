<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Models\Requisition;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Redirect;

class RequisitionExportActions
{
    public static function exportRisAction(): Action
    {
        return Action::make('exportRis')
            ->label('Export RIS (Appendix 63)')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function (Requisition $record) {
                return Redirect::away(route('owwa.export.requisition', $record));
            });
    }
}
