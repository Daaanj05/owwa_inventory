<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRequisition extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = RequisitionResource::class;
}
