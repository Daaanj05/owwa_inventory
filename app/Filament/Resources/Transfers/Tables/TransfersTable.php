<?php

namespace App\Filament\Resources\Transfers\Tables;

use App\Filament\Resources\Transfers\Actions\TransferViewActions;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\Transfer;
use App\Support\OwwaReferenceLabels;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::transfer())
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
            ->emptyStateHeading('No transfers recorded')
            ->emptyStateDescription('Stock transfers between OWWA offices will appear here.')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (Transfer $record): array => OwwaTransactionViewPresenter::forTransfer($record),
                        TransferResource::modalDetailSections(),
                    ),
                    [
                        TransferViewActions::editAction(),
                        TransferViewActions::exportOwwaAction(),
                        TransferViewActions::printViewAction(),
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
