<?php

namespace App\Filament\Resources\AiProcurementRunResource\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\AiProcurementRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAiProcurementRun extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = AiProcurementRunResource::class;
}
