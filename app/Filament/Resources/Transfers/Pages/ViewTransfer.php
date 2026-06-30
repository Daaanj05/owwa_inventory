<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\Transfers\TransferResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTransfer extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = TransferResource::class;
}
