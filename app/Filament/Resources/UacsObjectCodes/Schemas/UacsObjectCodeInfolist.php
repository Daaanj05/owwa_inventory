<?php

namespace App\Filament\Resources\UacsObjectCodes\Schemas;

use App\Models\ItemAttributeOption;
use Filament\Infolists\Components\TextEntry;

class UacsObjectCodeInfolist
{
    /**
     * @return array<int, TextEntry>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('name')
                ->label('Description')
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('property_class')
                ->label('Property class')
                ->formatStateUsing(fn (?string $state): string => filled($state)
                    ? ItemAttributeOption::labelFor(ItemAttributeOption::KIND_PROPERTY_CLASS, $state)
                    : '—')
                ->placeholder('—'),
            TextEntry::make('status')
                ->label('Status')
                ->state(fn ($record): string => $record->is_active ? 'Active' : 'Archived')
                ->badge()
                ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
        ];
    }
}
