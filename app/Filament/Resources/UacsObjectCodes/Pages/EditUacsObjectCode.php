<?php

namespace App\Filament\Resources\UacsObjectCodes\Pages;

use App\Filament\Resources\UacsObjectCodes\UacsObjectCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUacsObjectCode extends EditRecord
{
    protected static string $resource = UacsObjectCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
