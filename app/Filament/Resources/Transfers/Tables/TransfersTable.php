<?php

namespace App\Filament\Resources\Transfers\Tables;

use App\Filament\Resources\Transfers\TransferResource;
use App\Models\ItemCategory;
use App\Models\Transfer;
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

class TransfersTable
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
                TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('fromOffice.name')
                    ->label('From')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('toOffice.name')
                    ->label('To')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('condition')
                    ->label('Condition')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Serviceable', 'Good' => 'success',
                        'Unserviceable' => 'danger',
                        'Poor' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('transfer_date', 'desc')
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
            ->emptyStateHeading('No transfers recorded')
            ->emptyStateDescription('Stock transfers between OWWA offices will appear here.')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->recordUrl(fn (Transfer $record): string => TransferResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl'),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive transfer')
                    ->modalDescription('This transfer will be archived and hidden from the default list. You can restore it later using the filter.')
                    ->action(fn (Transfer $record) => $record->delete())
                    ->visible(fn (Transfer $record): bool => ! $record->trashed()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Transfer $record) => $record->restore())
                    ->visible(fn (Transfer $record): bool => $record->trashed()),
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
