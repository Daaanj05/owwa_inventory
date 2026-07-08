<?php

namespace App\Filament\Resources\Offices\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OfficeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label('Office code')
                    ->required()
                    ->placeholder('e.g. RO-NCR')
                    ->maxLength(30),
                Toggle::make('is_satellite')
                    ->label('Satellite office')
                    ->helperText('Check if this is a satellite or extension office.')
                    ->live()
                    ->disabled(fn (Get $get): bool => (bool) $get('is_regional_supply'))
                    ->dehydrated()
                    ->columnSpanFull(),
                Toggle::make('is_regional_supply')
                    ->label('Regional supply office')
                    ->helperText('Stock shown in Regional Supply Catalog comes from this office. Only one office can be designated.')
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if ($state) {
                            $set('is_satellite', false);
                        }
                    })
                    ->columnSpanFull(),
                Textarea::make('address')
                    ->columnSpanFull()
                    ->rows(3),
            ]);
    }
}
