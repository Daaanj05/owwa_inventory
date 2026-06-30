<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\Disposals\DisposalResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDisposal extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = DisposalResource::class;
}
