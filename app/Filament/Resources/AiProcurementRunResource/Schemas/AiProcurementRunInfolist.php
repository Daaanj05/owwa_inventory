<?php

namespace App\Filament\Resources\AiProcurementRunResource\Schemas;

use App\Models\AiProcurementRun;
use App\Support\AiProcurementRunViewPresenter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;

class AiProcurementRunInfolist
{
    /**
     * @return array<int, Section|SchemaView>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Recommendation')
                ->schema([
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->markdown()
                        ->placeholder('No recommendation text was saved for this run.')
                        ->columnSpanFull(),
                ]),
            Section::make('Recommended items')
                ->schema([
                    SchemaView::make('filament.resources.ai-procurement-run-resource.partials.modal-items-table')
                        ->viewData(fn (AiProcurementRun $record): array => [
                            'items' => AiProcurementRunViewPresenter::itemsForRecord($record),
                        ]),
                ]),
        ];
    }
}
