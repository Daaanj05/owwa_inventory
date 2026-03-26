<?php

namespace App\Filament\Resources\ReferenceSeries\Pages;

use App\Filament\Resources\ReferenceSeries\ReferenceSeriesResource;
use Filament\Resources\Pages\ListRecords;

class ListReferenceSeries extends ListRecords
{
    protected static string $resource = ReferenceSeriesResource::class;

    protected static ?string $title = 'Reference number formats';
}
