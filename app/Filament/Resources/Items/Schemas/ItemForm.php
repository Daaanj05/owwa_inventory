<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\ItemCategory;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item details')
                    ->description('Basic information about this inventory item.')
                    ->columns(2)
                    ->schema([
                        Select::make('item_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('item_code')
                            ->label('Item code')
                            ->placeholder('e.g. ITM-001')
                            ->maxLength(100),
                        TextInput::make('unit')
                            ->required()
                            ->default('piece')
                            ->maxLength(50),
                        Select::make('value_type')
                            ->label('Value type')
                            ->options(['low' => 'Low value', 'high' => 'High value'])
                            ->default('low')
                            ->visible(function (Get $get): bool {
                                $categoryId = $get('item_category_id');
                                if (blank($categoryId)) {
                                    return false;
                                }
                                $category = ItemCategory::find($categoryId);

                                return $category && in_array(
                                    strtolower(trim($category->name)),
                                    ['semi-expendable', 'semi expendable', 'semi_expendable'],
                                    true,
                                );
                            }),
                        TextInput::make('reorder_level')
                            ->label('Reorder point')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
            ]);
    }
}
