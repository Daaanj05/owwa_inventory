<?php

namespace App\Filament\Resources\UacsObjectCodes\Pages;

use App\Filament\Resources\UacsObjectCodes\UacsObjectCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUacsObjectCodes extends ListRecords
{
    protected static string $resource = UacsObjectCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
