<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAcquisition extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = AcquisitionResource::class;
}
