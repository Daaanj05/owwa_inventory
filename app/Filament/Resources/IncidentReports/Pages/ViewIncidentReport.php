<?php

namespace App\Filament\Resources\IncidentReports\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewIncidentReport extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = IncidentReportResource::class;
}
