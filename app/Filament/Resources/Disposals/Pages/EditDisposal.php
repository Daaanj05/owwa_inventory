<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Resources\Disposals\DisposalResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDisposal extends EditRecord
{
    protected static string $resource = DisposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->modalHeading('Archive disposal')
                ->modalDescription('This disposal will be archived and hidden from the default list.'),
            RestoreAction::make(),
        ];
    }
}
