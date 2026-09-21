<?php

namespace App\Filament\Resources\ItemAttributeOptions\Pages;

use App\Filament\Resources\ItemAttributeOptions\ItemAttributeOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItemAttributeOptions extends ManageRecords
{
    protected static string $resource = ItemAttributeOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
