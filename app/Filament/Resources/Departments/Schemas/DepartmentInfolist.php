<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Filament\Support\AdminRecordInfolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class DepartmentInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Department details')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Name'),
                    TextEntry::make('code')
                        ->label('Code')
                        ->placeholder('—'),
                    TextEntry::make('office.name')
                        ->label('Office'),
                    AdminRecordInfolist::archivedStatusEntry(),
                ]),
        ];
    }
}
