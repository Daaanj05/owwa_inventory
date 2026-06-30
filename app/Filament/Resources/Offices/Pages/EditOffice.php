<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Offices\OfficeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOffice extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = OfficeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
