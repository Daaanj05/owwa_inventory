<?php

namespace App\Filament\Resources\Departments\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = DepartmentResource::class;
}
