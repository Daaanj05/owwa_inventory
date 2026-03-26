<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\Acquisition;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcquisitionsTable
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
                    ->sortable(),
                TextColumn::make('acquisition_date')
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
                    ->alignEnd(),
                TextColumn::make('source')
                    ->label('Source')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('acquisition_date', 'desc')
            ->emptyStateHeading('No acquisitions recorded')
            ->emptyStateDescription('Stock received from suppliers or procurement will appear here.')
            ->emptyStateIcon('heroicon-o-arrow-down-tray')
            ->recordUrl(fn (Acquisition $record): string => AcquisitionResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl'),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive acquisition')
                    ->modalDescription('This acquisition will be archived and hidden from the default list. You can restore it later using the filter.')
                    ->action(fn (Acquisition $record) => $record->delete())
                    ->visible(fn (Acquisition $record): bool => ! $record->trashed()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Acquisition $record) => $record->restore())
                    ->visible(fn (Acquisition $record): bool => $record->trashed()),
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
