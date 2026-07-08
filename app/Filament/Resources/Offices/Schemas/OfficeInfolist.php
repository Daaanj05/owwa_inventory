<?php

namespace App\Filament\Resources\Offices\Schemas;

use App\Filament\Support\AdminRecordInfolist;
use Filament\Infolists\Components\TextEntry;

class OfficeInfolist
{
    /**
     * @return array<int, TextEntry|\Filament\Infolists\Components\IconEntry>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('code')
                ->label('Code'),
            AdminRecordInfolist::booleanEntry('is_satellite', 'Satellite office'),
            AdminRecordInfolist::booleanEntry('is_regional_supply', 'Regional supply office'),
            TextEntry::make('address')
                ->label('Address')
                ->placeholder('—')
                ->columnSpanFull(),
        ];
    }
}
