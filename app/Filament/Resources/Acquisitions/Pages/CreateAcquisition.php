<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAcquisition extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = AcquisitionResource::class;
}
