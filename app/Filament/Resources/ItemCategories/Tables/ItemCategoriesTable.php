<?php

namespace App\Filament\Resources\ItemCategories\Tables;

use App\Filament\Resources\ItemCategories\Schemas\ItemCategoryInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
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
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn ($record): array => OwwaTransactionViewPresenter::forAdminRecord($record, 'Category'),
                        ItemCategoryInfolist::modalDetailSections(),
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
                        ->modalHeading('Archive category')
                        ->modalDescription('This category will be hidden from active lists but kept for historical data. Use the Archived tab to view or restore it.')
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
