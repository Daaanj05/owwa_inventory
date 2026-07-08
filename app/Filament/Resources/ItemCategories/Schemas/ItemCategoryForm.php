<?php

namespace App\Filament\Resources\ItemCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ItemCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'A category with this name already exists.',
                    ]),
                TextInput::make('description')
                    ->maxLength(255)
                    ->placeholder('Optional short description'),
            ]);
    }
}
