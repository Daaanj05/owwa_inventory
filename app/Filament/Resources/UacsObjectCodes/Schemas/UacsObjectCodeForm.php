<?php

namespace App\Filament\Resources\UacsObjectCodes\Schemas;

use App\Support\ItemPropertyClass;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UacsObjectCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('UACS / GL object code')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->helperText('CODE NUMBER segment used in Inventory item no. / Property No.'),
                TextInput::make('name')
                    ->label('Description')
                    ->required()
                    ->maxLength(255),
                Select::make('property_class')
                    ->label('Property class (optional filter)')
                    ->options(ItemPropertyClass::options())
                    ->searchable()
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
