<?php

namespace App\Filament\Resources\Requisitions\RelationManagers;

use App\Rules\UniqueRequisitionItemPerRequisition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Requisition Items';

    public function form(Schema $schema): Schema
    {
        $requisitionId = $this->getOwnerRecord()->getKey();

        return $schema
            ->components([
                Select::make('item_id')
                    ->relationship('item', 'name')
                    ->required()
                    ->searchable()
                    ->rules([new UniqueRequisitionItemPerRequisition($requisitionId)]),
                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('remarks'),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->columns([
                TextColumn::make('item.name')->label('Item'),
                TextColumn::make('quantity'),
                TextColumn::make('remarks'),
            ]);
    }
}
