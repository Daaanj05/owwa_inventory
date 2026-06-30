<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Models\PhysicalCountSession;
use App\Support\PhysicalCountSessionViewPresenter;
use Filament\Schemas\Components\View as SchemaView;

class PhysicalCountSessionModalSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function components(): array
    {
        return [
            SchemaView::make('filament.resources.physical-count-sessions.pages.partials.view-physical-count-hero')
                ->viewData(fn (PhysicalCountSession $record): array => PhysicalCountSessionViewPresenter::forSession($record)),
            ...PhysicalCountSessionInfolist::modalDetailSections(),
        ];
    }
}
