<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Schemas;

use App\Models\AcquisitionPaperwork;
use App\Support\AcquisitionPaperworkViewPresenter;
use Filament\Schemas\Components\View as SchemaView;

class AcquisitionPaperworkModalSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function components(): array
    {
        return [
            SchemaView::make('filament.resources.acquisitions.paperwork.partials.view-acquisition-paperwork-hero')
                ->viewData(fn (AcquisitionPaperwork $record): array => AcquisitionPaperworkViewPresenter::forPaperwork($record)),
            ...AcquisitionPaperworkInfolist::modalDetailSections(),
        ];
    }
}
