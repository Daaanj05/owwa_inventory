<?php

namespace App\Filament\Resources\Disposals\Tables;

use App\Filament\Resources\Disposals\Actions\DisposalViewActions;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\Disposal;
use App\Support\OwwaReferenceLabels;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DisposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('reference_code')
                    ->label(fn (): string => OwwaReferenceLabels::disposal())
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
            ->emptyStateHeading('No disposals recorded')
            ->emptyStateDescription('Items written off, damaged, or expired will be listed here.')
            ->emptyStateIcon('heroicon-o-trash')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (Disposal $record): array => OwwaTransactionViewPresenter::forDisposal($record),
                        DisposalResource::modalDetailSections(),
                    ),
                    [
                        DisposalViewActions::editAction(),
                        DisposalViewActions::exportOwwaAction(),
                        DisposalViewActions::printViewAction(),
                    ],
                    '3xl',
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD),
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
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
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
    }
}
