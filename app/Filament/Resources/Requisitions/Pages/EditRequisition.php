<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Redirect;

class EditRequisition extends EditRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('exportRis')
                ->label('Export RIS')
                ->icon('heroicon-o-document-arrow-down')
                ->action(function () {
                    $url = route('owwa.export.requisition', $this->record);

                    return Redirect::away($url);
                }),
        ];
    }
}
