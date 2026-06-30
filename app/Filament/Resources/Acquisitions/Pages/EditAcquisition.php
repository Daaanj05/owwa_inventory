<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAcquisition extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = AcquisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->modalHeading('Archive acquisition')
                    ->modalDescription('This acquisition will be archived and hidden from the default list.'),
                RestoreAction::make(),
            ])
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }
}
