<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Resources\Offices\OfficeResource;
use App\Services\FiscalYearService;
use Filament\Resources\Pages\CreateRecord;

class CreateOffice extends CreateRecord
{
    protected static string $resource = OfficeResource::class;

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
