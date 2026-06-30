<?php

namespace App\Filament\Resources\Offices\Schemas;

use App\Filament\Support\AdminRecordInfolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class OfficeInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Office details')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Name'),
                    TextEntry::make('code')
                        ->label('Code'),
                    TextEntry::make('fund_cluster')
                        ->label('Fund cluster')
                        ->placeholder('—'),
                    AdminRecordInfolist::booleanEntry('is_satellite', 'Satellite office'),
                    AdminRecordInfolist::archivedStatusEntry(),
                    TextEntry::make('address')
                        ->label('Address')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('Default signatories')
                ->columns(2)
                ->schema([
                    TextEntry::make('supply_custodian_name')
                        ->label('Supply custodian')
                        ->placeholder('—'),
                    TextEntry::make('supply_custodian_designation')
                        ->label('Custodian designation')
                        ->placeholder('—'),
                    TextEntry::make('authorized_officer_name')
                        ->label('Authorized officer')
                        ->placeholder('—'),
                    TextEntry::make('authorized_officer_designation')
                        ->label('Officer designation')
                        ->placeholder('—'),
                    TextEntry::make('accountable_officer_name')
                        ->label('Accountable officer')
                        ->placeholder('—'),
                    TextEntry::make('accountable_officer_designation')
                        ->label('Accountable designation')
                        ->placeholder('—'),
                    TextEntry::make('inspection_officer_name')
                        ->label('Inspection officer')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
