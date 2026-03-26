<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Services\FiscalYearService;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $fiscal = app(FiscalYearService::class);
        $current = $fiscal->current();
        if ($current) {
            $data['fiscal_year_id'] = $current->id;
        }

        return $data;
    }
}
