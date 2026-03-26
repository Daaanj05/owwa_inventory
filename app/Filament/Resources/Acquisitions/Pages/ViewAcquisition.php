<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAcquisition extends ViewRecord
{
    protected static string $resource = AcquisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->modalWidth('5xl'),
        ];
    }
}
