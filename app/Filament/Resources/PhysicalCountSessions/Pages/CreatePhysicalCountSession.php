<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\RedirectsCreateToList;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePhysicalCountSession extends CreateRecord
{
    use RedirectsCreateToList;

    protected static string $resource = PhysicalCountSessionResource::class;
}
