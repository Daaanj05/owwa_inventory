<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\Issuances\IssuanceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewIssuance extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = IssuanceResource::class;
}
