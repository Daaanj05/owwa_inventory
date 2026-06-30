<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Offices\OfficeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOffice extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = OfficeResource::class;
}
