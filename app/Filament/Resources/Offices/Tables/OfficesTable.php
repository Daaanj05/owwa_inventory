<?php

namespace App\Filament\Resources\Offices\Tables;

use App\Filament\Resources\Offices\Schemas\OfficeInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
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
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn ($record): array => OwwaTransactionViewPresenter::forAdminRecord($record, 'Office'),
                        OfficeInfolist::modalDetailSections(),
                    ),
                    [
                        OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    ],
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive office')
                        ->modalDescription('This office will be hidden from active lists but kept for historical data. Use the tabs above the table to view archived records.')
                        ->visible(fn ($record) => $record->archived_at === null)
                        ->action(fn ($record) => $record->update(['archived_at' => now()])),
                    Action::make('unarchive')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn ($record) => $record->archived_at !== null)
                        ->action(fn ($record) => $record->update(['archived_at' => null])),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordUrl(null)
            ->recordAction('view')
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['archived_at' => now()])),
                ]),
            ]);
    }
}
