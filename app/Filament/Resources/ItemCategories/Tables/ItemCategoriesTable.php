<?php

namespace App\Filament\Resources\ItemCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('description')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('Categories group inventory items for easier filtering and reporting. Create at least one before adding items.')
            ->emptyStateIcon('heroicon-o-tag')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
