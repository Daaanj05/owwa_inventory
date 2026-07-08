<?php

namespace App\Filament\Resources\PhysicalCountSessions\Tables;

use App\Filament\Resources\PhysicalCountSessions\Actions\PhysicalCountSessionActions;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Models\PhysicalCountSession;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PhysicalCountSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::physicalCount())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PhysicalCountSession::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalCountSession::STATUS_INCOMPLETE => 'Incomplete',
                        PhysicalCountSession::STATUS_COMPLETE => 'Complete',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PhysicalCountSession::STATUS_COMPLETE => 'success',
                        PhysicalCountSession::STATUS_INCOMPLETE => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('count_type')
                    ->label('Form')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'rpcppe' => 'RPCPPE',
                        'rpcsp' => 'RPCSP',
                        default => 'RPCI',
                    }),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable(),
                TextColumn::make('tally')
                    ->label('Tally')
                    ->state(fn (PhysicalCountSession $record): string => $record->tallyLabel())
                    ->color(function (PhysicalCountSession $record): string {
                        $summary = $record->countSummary();

                        if ($summary['shortages'] > 0) {
                            return 'danger';
                        }

                        if ($summary['expected'] > 0 && $summary['scanned'] >= $summary['expected']) {
                            return 'success';
                        }

                        return 'gray';
                    }),
                TextColumn::make('count_date')
                    ->label('As at')
                    ->date()
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
                TextColumn::make('archived_at')
                    ->label('Archive')
                    ->badge()
                    ->state(fn (PhysicalCountSession $record): string => $record->isArchived() ? 'Archived' : 'Active')
                    ->color(fn (PhysicalCountSession $record): string => $record->isArchived() ? 'gray' : 'success')
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'active') === 'archived'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PhysicalCountSession::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalCountSession::STATUS_INCOMPLETE => 'Incomplete',
                        PhysicalCountSession::STATUS_COMPLETE => 'Complete',
                    ]),
            ])
            ->defaultSort('count_date', 'desc')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: PhysicalCountSessionModalSchema::components(),
                    footerActions: PhysicalCountSessionActions::modalFooterActions(),
                    modalWidth: '5xl',
                    extraModalClass: 'owwa-physical-count-modal',
                    modelLabel: PhysicalCountSessionResource::getModelLabel(),
                ),
                ActionGroup::make([
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive physical count session')
                        ->modalDescription('This session will be hidden from the active list but kept for history. Use the Archived tab to view or restore it.')
                        ->visible(fn (PhysicalCountSession $record): bool => ! $record->isArchived())
                        ->action(fn (PhysicalCountSession $record) => $record->update(['archived_at' => now()])),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (PhysicalCountSession $record): bool => $record->isArchived())
                        ->action(fn (PhysicalCountSession $record) => $record->update(['archived_at' => null])),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['archived_at' => now()])),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }
}
