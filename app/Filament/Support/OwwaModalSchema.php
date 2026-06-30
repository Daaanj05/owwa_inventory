<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\View as SchemaView;
use Illuminate\Database\Eloquent\Model;

class OwwaModalSchema
{
    /**
     * @param  callable(Model): array<string, mixed>  $heroData
     * @param  array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>  $detailSections
     * @return array<int, SchemaView|\Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function withHero(callable $heroData, array $detailSections): array
    {
        return [
            SchemaView::make('filament.partials.owwa-record-hero')
                ->viewData($heroData),
            ...$detailSections,
        ];
    }
}
