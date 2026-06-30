<?php

namespace App\Filament\Support;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;

class AdminRecordInfolist
{
    public static function archivedStatusEntry(): TextEntry
    {
        return TextEntry::make('archived_at')
            ->label('Status')
            ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
            ->badge()
            ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success');
    }

    public static function booleanEntry(string $field, string $label): IconEntry
    {
        return IconEntry::make($field)
            ->label($label)
            ->boolean();
    }
}
