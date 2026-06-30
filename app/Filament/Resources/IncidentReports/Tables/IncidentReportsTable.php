<?php

namespace App\Filament\Resources\IncidentReports\Tables;

use App\Filament\Resources\IncidentReports\Actions\IncidentReportViewActions;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
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

class IncidentReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('reference_code')
                    ->label(fn (): string => OwwaReferenceLabels::incidentReport())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('item.category.name')
                    ->label('Category')
                    ->placeholder('—'),
                TextColumn::make('disposal_date')
                    ->label('Date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('property_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->defaultSort('disposal_date', 'desc')
            ->emptyStateHeading('No incident reports recorded')
            ->emptyStateDescription('Lost, stolen, damaged, or destroyed property reports will appear here.')
            ->emptyStateIcon('heroicon-o-exclamation-triangle')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (Disposal $record): array => OwwaTransactionViewPresenter::forDisposal($record),
                        IncidentReportResource::modalDetailSections(),
                    ),
                    [
                        IncidentReportViewActions::editAction(),
                        IncidentReportViewActions::exportOwwaAction(),
                        IncidentReportViewActions::printViewAction(),
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
