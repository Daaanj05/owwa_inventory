<?php

namespace App\Filament\Resources\Items\Tables;

use App\Filament\Resources\Items\Actions\ItemViewActions;
use App\Filament\Resources\Items\Schemas\ItemInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\ItemCategory;
use App\Support\OwwaTransactionViewPresenter;
use App\Support\SemiExpendableValueCategory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->defaultSort('name')
            ->filters(self::filters())
            ->emptyStateHeading('No items yet')
            ->emptyStateDescription('Add inventory items here before recording acquisitions or issuances.')
            ->emptyStateIcon('heroicon-o-cube')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn ($record): array => OwwaTransactionViewPresenter::forItem($record),
                        ItemInfolist::modalDetailSections(),
                    ),
                    [
                        OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                        ItemViewActions::exportOwwaItemReportAction(),
                    ],
                    '5xl',
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    ItemViewActions::exportOwwaItemReportAction(),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive item')
                        ->modalDescription('This item will be hidden from active lists but kept for historical data. Use the tabs above the table to view archived records.')
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
                    \Filament\Actions\BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['archived_at' => now()])),
                ]),
            ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    public static function columns(): array
    {
        $columns = [
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                ->weight(\Filament\Support\Enums\FontWeight::Medium)
                ->wrap()
                ->grow(),
            TextColumn::make('item_code')
                ->label('Stock No.')
                ->searchable()
                ->placeholder('—')
                ->grow(false),
            TextColumn::make('unit')
                ->label('Measurement unit')
                ->searchable()
                ->grow(false),
        ];

        if (self::isActiveSemiExpendableCategory()) {
            $columns[] = TextColumn::make('value_type')
                ->label('Value category')
                ->formatStateUsing(fn (?string $state): string => SemiExpendableValueCategory::labelForValueType($state))
                ->badge()
                ->color(fn (?string $state): string => $state === 'high' ? 'warning' : 'gray')
                ->grow(false);
        }

        $columns[] = TextColumn::make('reorder_level')
            ->label('Reorder at')
            ->numeric()
            ->sortable()
            ->width('5rem')
            ->grow(false);

        $columns[] = TextColumn::make('status')
            ->label('Status')
            ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
            ->badge()
            ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success')
            ->grow(false);

        return $columns;
    }

    /**
     * @return array<int, SelectFilter>
     */
    public static function filters(): array
    {
        if (! self::isActiveSemiExpendableCategory()) {
            return [];
        }

        return [
            SelectFilter::make('value_type')
                ->label('Value type')
                ->options([
                    'low' => 'Low value',
                    'high' => 'High value',
                ])
                ->placeholder('All types'),
        ];
    }

    public static function isActiveSemiExpendableCategory(): bool
    {
        $categoryId = (int) session('active_item_category_id', 0);

        if ($categoryId <= 0) {
            return false;
        }

        $category = ItemCategory::query()->find($categoryId);

        return $category?->getTemplateSlug() === 'semi_expendable';
    }
}
