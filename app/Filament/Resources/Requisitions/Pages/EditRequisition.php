<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\RedirectsEditToViewModal;
use App\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Resources\Pages\EditRecord;

class EditRequisition extends EditRecord
{
    use RedirectsEditToViewModal;

    protected static string $resource = RequisitionResource::class;
}
