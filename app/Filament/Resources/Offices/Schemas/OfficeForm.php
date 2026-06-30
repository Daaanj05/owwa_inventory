<?php

namespace App\Filament\Resources\Offices\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OfficeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Office details')
                    ->description('OWWA offices or satellite offices across the country.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Office code')
                            ->required()
                            ->placeholder('e.g. RO-NCR')
                            ->maxLength(30),
                        TextInput::make('fund_cluster')
                            ->label('Fund cluster')
                            ->placeholder('For OWWA forms (Entity / Fund Cluster)')
                            ->maxLength(255),
                        Toggle::make('is_satellite')
                            ->label('Satellite office')
                            ->helperText('Check if this is a satellite or extension office.')
                            ->columnSpanFull(),
                        Textarea::make('address')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
                Section::make('Default signatories (OWWA exports)')
                    ->description('Optional printed names used to pre-fill transfer, disposal, and issuance forms. Custodians can override per transaction.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('supply_custodian_name')
                            ->label('Supply custodian name')
                            ->maxLength(255),
                        TextInput::make('supply_custodian_designation')
                            ->label('Supply custodian designation')
                            ->maxLength(255),
                        TextInput::make('authorized_officer_name')
                            ->label('Authorized / approving officer')
                            ->maxLength(255),
                        TextInput::make('authorized_officer_designation')
                            ->label('Approving officer designation')
                            ->maxLength(255),
                        TextInput::make('accountable_officer_name')
                            ->label('Accountable officer')
                            ->maxLength(255),
                        TextInput::make('accountable_officer_designation')
                            ->label('Accountable officer designation')
                            ->maxLength(255),
                        TextInput::make('inspection_officer_name')
                            ->label('Inspection officer')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
