<?php

namespace App\Filament\Resources\Items\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('item_code')
                    ->label('Item code')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('unit')
                    ->searchable(),
                TextColumn::make('value_type')
                    ->label('Value')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reorder_level')
                    ->label('Reorder at')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('item_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All categories'),
                SelectFilter::make('value_type')
                    ->label('Value type')
                    ->options([
                        'low'  => 'Low value',
                        'high' => 'High value',
                    ])
                    ->placeholder('All types'),
            ])
            ->emptyStateHeading('No items yet')
            ->emptyStateDescription('Add inventory items here before recording acquisitions or issuances.')
            ->emptyStateIcon('heroicon-o-cube')
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl'),
                \Filament\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Archive item')
                    ->modalDescription('This item will be hidden from active lists but kept for historical data. Use the tabs above the table to view archived records.')
                    ->visible(fn ($record) => $record->archived_at === null)
                    ->action(fn ($record) => $record->update(['archived_at' => now()])),
                \Filament\Actions\Action::make('unarchive')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn ($record) => $record->archived_at !== null)
                    ->action(fn ($record) => $record->update(['archived_at' => null])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['archived_at' => now()])),
                ]),
            ]);
    }
}
