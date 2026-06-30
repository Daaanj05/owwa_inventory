<?php

namespace App\Filament\Resources\AiProcurementRunResource\Schemas;

use App\Models\AiProcurementRun;
use App\Support\AiProcurementRunViewPresenter;
use Filament\Schemas\Components\View as SchemaView;

class AiProcurementRunModalSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function components(): array
    {
        return [
            SchemaView::make('filament.resources.ai-procurement-run-resource.partials.modal-header')
                ->viewData(fn (AiProcurementRun $record): array => AiProcurementRunViewPresenter::forRecord($record)),
            ...AiProcurementRunInfolist::modalDetailSections(),
        ];
    }
}
