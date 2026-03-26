<?php

namespace App\Filament\Resources\Departments\Pages;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Services\FiscalYearService;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

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
