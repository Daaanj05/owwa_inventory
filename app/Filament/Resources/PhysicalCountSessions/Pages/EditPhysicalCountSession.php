<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\RedirectsEditToViewModal;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalCountSession extends EditRecord
{
    use RedirectsEditToViewModal;

    protected static string $resource = PhysicalCountSessionResource::class;
}
