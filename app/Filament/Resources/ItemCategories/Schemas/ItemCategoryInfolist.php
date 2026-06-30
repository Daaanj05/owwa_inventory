<?php

namespace App\Filament\Resources\ItemCategories\Schemas;

use App\Filament\Support\AdminRecordInfolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class ItemCategoryInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Category details')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Name'),
                    AdminRecordInfolist::archivedStatusEntry(),
                    TextEntry::make('description')
                        ->label('Description')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
