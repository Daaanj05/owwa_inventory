<?php

namespace App\Filament\Resources\Issuances\Tables;

use App\Filament\Resources\Issuances\Actions\IssuanceViewActions;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\Issuance;
use App\Support\OwwaReferenceLabels;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IssuancesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('batch.reference_code')
                    ->label(fn (): string => OwwaReferenceLabels::issuanceControl())
                    ->state(fn (Issuance $record): ?string => $record->controlNumber())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->where('reference_code', 'like', "%{$search}%")
                                ->orWhereHas('batch', fn (Builder $batchQuery): Builder => $batchQuery->where('reference_code', 'like', "%{$search}%"));
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->leftJoin('issuance_batches', 'issuances.issuance_batch_id', '=', 'issuance_batches.id')
                            ->orderByRaw('COALESCE(issuance_batches.reference_code, issuances.reference_code) '.$direction)
                            ->select('issuances.*');
                    })
                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                    ->description(fn (Issuance $record): ?string => str_starts_with(strtoupper((string) $record->controlNumber()), 'RIS-')
                        ? 'Legacy code — use issuance series (YYYY-MM-####), not RIS prefix'
                        : null)
                    ->color(fn (Issuance $record): ?string => str_starts_with(strtoupper((string) $record->controlNumber()), 'RIS-')
                        ? 'warning'
                        : null),
                TextColumn::make('requisition.reference_code')
                    ->label(OwwaReferenceLabels::RIS)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('item.item_code')
                    ->label(OwwaReferenceLabels::STOCK_NO)
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                    ->label('Unit cost (₱ per UOM)')
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
            ->emptyStateHeading('No issuances recorded')
            ->emptyStateDescription('Issuances are created from Requisitions → Accept & issue (or Issue remainder). Export RSMI here after issue; export RIS from Requisitions.')
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (Issuance $record): array => OwwaTransactionViewPresenter::forIssuance($record),
                        IssuanceResource::modalDetailSections(),
                    ),
                    [
                        IssuanceViewActions::editAction(),
                        IssuanceViewActions::exportOwwaAction(),
                        IssuanceViewActions::extendUsefulLifeAction(),
                        IssuanceViewActions::printQrLabelAction(),
                        IssuanceViewActions::printViewAction(),
                    ],
                    '4xl',
                    modelLabel: IssuanceResource::getModelLabel(),
                ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Archive selected')
                        ->visible(fn (): bool => in_array($table->getLivewire()->activeTab ?? 'active', ['active', 'all'], true)),
                    RestoreBulkAction::make()
                        ->visible(fn (): bool => in_array($table->getLivewire()->activeTab ?? 'active', ['archived', 'all'], true)),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }
}
