<?php

namespace App\Filament\Resources\Departments\Schemas;

use Filament\Infolists\Components\TextEntry;

class DepartmentInfolist
{
    /**
     * @return array<int, TextEntry>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('code')
                ->label('Code')
                ->placeholder('—'),
            TextEntry::make('office.name')
                ->label('Office'),
        ];
    }
}
