<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Resources\Transfers\TransferResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTransfer extends EditRecord
{
    protected static string $resource = TransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->modalHeading('Archive transfer')
                ->modalDescription('This transfer will be archived and hidden from the default list.'),
            RestoreAction::make(),
        ];
    }
}
