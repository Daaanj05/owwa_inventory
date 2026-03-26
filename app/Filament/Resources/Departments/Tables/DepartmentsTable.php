<?php

namespace App\Filament\Resources\Departments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use App\Services\FiscalYearService;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('office_id')
                    ->label('Office')
                    ->relationship(
                        'office',
                        'name',
                        fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active()
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('All offices'),
            ])
            ->emptyStateHeading('No departments yet')
            ->emptyStateDescription('Create departments under their respective offices. Departments are used to track issuances and requisitions.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->recordActions([
                EditAction::make(),
                \Filament\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Archive department')
                    ->modalDescription('This department will be hidden from active lists but kept for historical data. Use the tabs above the table to view archived records.')
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
