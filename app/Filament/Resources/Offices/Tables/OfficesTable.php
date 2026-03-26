<?php

namespace App\Filament\Resources\Offices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfficesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_satellite')
                    ->label('Satellite')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No offices yet')
            ->emptyStateDescription('Add OWWA regional or satellite offices to get started.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->recordActions([
                EditAction::make(),
                \Filament\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Archive office')
                    ->modalDescription('This office will be hidden from active lists but kept for historical data. Use the tabs above the table to view archived records.')
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
