<?php

namespace App\Filament\Resources\Disposals\Tables;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Models\Disposal;
use App\Models\ItemCategory;
use App\Services\FiscalYearService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DisposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('disposal_date')
                    ->label('Date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('disposal_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'waste_sale' => 'Waste / Sale',
                        'unserviceable' => 'Unserviceable',
                        'lost_stolen_damaged' => 'Lost / Damaged',
                        default => $state ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'waste_sale' => 'warning',
                        'unserviceable' => 'gray',
                        'lost_stolen_damaged' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('disposal_date', 'desc')
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('item_category_id')
                    ->label('Category')
                    ->options(fn (): array => cache()->remember(
                        'item_categories.options',
                        3600,
                        fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                    ))
                    ->default(fn (): mixed => session('active_item_category_id'))
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = $data['value'] ?? null;

                        if (! filled($categoryId)) {
                            session()->forget('active_item_category_id');
                            return $query->whereRaw('1 = 0');
                        }

                        session()->put('active_item_category_id', (int) $categoryId);

                        return $query->whereHas('item', function (Builder $itemQuery) use ($data): void {
                            $itemQuery->where('item_category_id', (int) $data['value']);
                        });
                    })
                    ->placeholder('Select a category'),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('No disposals recorded')
            ->emptyStateDescription('Items written off, damaged, or expired will be listed here.')
            ->emptyStateIcon('heroicon-o-trash')
            ->recordUrl(fn (Disposal $record): string => DisposalResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl'),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive disposal')
                    ->modalDescription('This disposal will be archived and hidden from the default list. You can restore it later using the filter.')
                    ->action(fn (Disposal $record) => $record->delete())
                    ->visible(fn (Disposal $record): bool => ! $record->trashed()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Disposal $record) => $record->restore())
                    ->visible(fn (Disposal $record): bool => $record->trashed()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Archive selected'),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
