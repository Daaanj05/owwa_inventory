<?php

namespace App\Filament\Resources\ReferenceSeries\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\ReferenceSeries\ReferenceSeriesResource;
use Filament\Resources\Pages\EditRecord;

class EditReferenceSeries extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = ReferenceSeriesResource::class;
}
