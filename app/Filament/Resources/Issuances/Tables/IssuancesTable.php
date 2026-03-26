<?php

namespace App\Filament\Resources\Issuances\Tables;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\Issuance;
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

class IssuancesTable
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
                TextColumn::make('issuance_date')
                    ->label('Date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('unit_cost')
                    ->label('Unit cost')
                    ->money('PHP')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PHP')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issuedTo.name')
                    ->label('Issued to')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('issuance_date', 'desc')
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
            ->emptyStateHeading('No issuances recorded')
            ->emptyStateDescription('Issued items will appear here once you record an issuance.')
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->recordUrl(fn (Issuance $record): string => IssuanceResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->modalWidth('7xl'),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive issuance')
                    ->modalDescription('This issuance will be archived and hidden from the default list. You can restore it later using the filter.')
                    ->action(fn (Issuance $record) => $record->delete())
                    ->visible(fn (Issuance $record): bool => ! $record->trashed()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Issuance $record) => $record->restore())
                    ->visible(fn (Issuance $record): bool => $record->trashed()),
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
