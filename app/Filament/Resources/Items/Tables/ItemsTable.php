<?php

namespace App\Filament\Resources\Items\Tables;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Schemas\ItemInfolist;
use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\CatalogAssetNumberService;
use App\Support\OwwaTransactionViewPresenter;
use App\Support\SemiExpendableValueCategory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
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
                        OwwaFormModalDefaults::editActionForResource(ItemResource::class, OwwaFormModalDefaults::WIDTH_COMPACT),
                    ],
                    '5xl',
                    modelLabel: ItemResource::getModelLabel(),
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editActionForResource(ItemResource::class, OwwaFormModalDefaults::WIDTH_COMPACT),
                    ItemOpeningStockFields::makeSetStartingStockAction(),
                    Action::make('downloadQrLabels')
                        ->label('Download QR labels')
                        ->icon('heroicon-o-qr-code')
                        ->url(fn (Item $record): string => route('owwa.qr-labels.item', $record))
                        ->openUrlInNewTab()
                        ->visible(function (Item $record): bool {
                            $slug = $record->category?->getTemplateSlug()
                                ?? $record->loadMissing('category')->category?->getTemplateSlug();

                            if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
                                return false;
                            }

                            $officeId = \App\Support\CustodianOfficeScope::inventoryOfficeId();

                            return \App\Models\InventoryUnit::query()
                                ->where('item_id', $record->id)
                                ->when($officeId !== null, fn ($q) => $q->where('office_id', $officeId))
                                ->exists();
                        }),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive item')
                        ->modalDescription('This item will be hidden from active lists but kept for history. Use the Archived tab to view or restore it.')
                        ->visible(fn (Item $record): bool => $record->archived_at === null)
                        ->action(fn (Item $record) => $record->update(['archived_at' => now()])),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->modalHeading('Restore item?')
                        ->modalDescription('This item will return to the Active list.')
                        ->visible(fn (Item $record): bool => $record->archived_at !== null)
                        ->action(fn (Item $record) => $record->update(['archived_at' => null])),
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
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'active') !== 'archived')
                        ->action(fn ($records) => $records->each(function (Item $record): void {
                            if ($record->archived_at === null) {
                                $record->update(['archived_at' => now()]);
                            }
                        })),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->modalHeading('Restore selected items?')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'active') === 'archived')
                        ->action(fn ($records) => $records->each(function (Item $record): void {
                            if ($record->archived_at !== null) {
                                $record->update(['archived_at' => null]);
                            }
                        })),
                ]),
            ]);

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }

    /**
     * @return array<int, TextColumn>
     */
    public static function columns(): array
    {
        $isPpeCategory = self::activeCategorySlug() === 'ppe';

        $columns = [
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                ->weight(\Filament\Support\Enums\FontWeight::Medium)
                ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                ->extraAttributes(['class' => 'owwa-item-name-column'])
                ->width($isPpeCategory ? '16rem' : null)
                ->grow(! $isPpeCategory),
            TextColumn::make('catalog_identifier')
                ->label(fn (): string => app(CatalogAssetNumberService::class)
                    ->catalogIdentifierLabel(self::activeCategorySlug()))
                ->state(fn (Item $record): ?string => $record->catalogAssetIdentifier())
                ->searchable(query: function ($query, string $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('item_code', 'like', "%{$search}%")
                            ->orWhere('semi_expendable_property_number', 'like', "%{$search}%")
                            ->orWhere('ppe_property_number', 'like', "%{$search}%");
                    });
                })
                ->placeholder('—')
                ->width($isPpeCategory ? '18rem' : null)
                ->extraAttributes(['class' => 'owwa-item-identifier-column'])
                ->grow(false),
            TextColumn::make('unit')
                ->label('Measurement unit')
                ->searchable()
                ->extraAttributes(['class' => 'owwa-item-unit-column'])
                ->grow(false),
        ];

        if (self::isActiveConsumablesCategory()) {
            $columns[] = TextColumn::make('base_name')
                ->label('Base item')
                ->searchable()
                ->placeholder('—')
                ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                ->width('18rem')
                ->extraAttributes(['class' => 'owwa-item-base-column'])
                ->grow(false);

            $columns[] = TextColumn::make('sub_item')
                ->label('Sub-item')
                ->searchable()
                ->placeholder('—')
                ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                ->extraAttributes(['class' => 'owwa-item-sub-column'])
                ->grow(false);
        }

        if (self::isActiveSemiExpendableCategory()) {
            $columns[] = TextColumn::make('value_type')
                ->label('Value category')
                ->formatStateUsing(fn (?string $state): string => SemiExpendableValueCategory::labelForValueType($state))
                ->badge()
                ->color(fn (?string $state): string => $state === 'high' ? 'warning' : 'gray')
                ->grow(false);
        }

        $columns[] = TextColumn::make('reorder_level')
            ->label('Reorder point')
            ->numeric()
            ->sortable()
            ->width('5rem')
            ->extraAttributes(['class' => 'owwa-item-reorder-column'])
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
        return self::activeCategorySlug() === 'semi_expendable';
    }

    public static function isActiveConsumablesCategory(): bool
    {
        return self::activeCategorySlug() === 'consumables';
    }

    public static function activeCategorySlug(): ?string
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

        if ($categoryId <= 0) {
            return null;
        }

        return ItemCategory::query()->find($categoryId)?->getTemplateSlug();
    }
}
