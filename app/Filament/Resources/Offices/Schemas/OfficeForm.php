<?php

namespace App\Filament\Resources\Offices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OfficeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Office details')
                    ->description('OWWA offices or satellite offices across the country.')
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
            ]);
    }
}
