<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPhysicalCountSession extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = PhysicalCountSessionResource::class;
}
