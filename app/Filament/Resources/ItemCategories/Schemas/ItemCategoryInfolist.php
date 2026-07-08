<?php

namespace App\Filament\Resources\ItemCategories\Schemas;

use Filament\Infolists\Components\TextEntry;

class ItemCategoryInfolist
{
    /**
     * @return array<int, TextEntry>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('description')
                ->label('Description')
                ->placeholder('—')
                ->columnSpanFull(),
        ];
    }
}
