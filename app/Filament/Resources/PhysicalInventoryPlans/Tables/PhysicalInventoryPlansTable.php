<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Tables;

use App\Filament\Resources\PhysicalInventoryPlans\Actions\PhysicalInventoryPlanActions;
use App\Filament\Resources\PhysicalInventoryPlans\Schemas\PhysicalInventoryPlanModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalInventoryPlan;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhysicalInventoryPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period_label')
                    ->label('Period')
                    ->placeholder('—'),
                TextColumn::make('cut_off_date')
                    ->label('Cut-off')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (PhysicalInventoryPlan $record): string => (function () use ($record): string {
                        $counts = $record->progressCounts();

                        return "{$counts['completed']} / {$counts['total']}";
                    })())
                    ->badge()
                    ->color(fn (PhysicalInventoryPlan $record): string => $record->isCompleted() ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PhysicalInventoryPlan::STATUS_DRAFT => 'Draft',
                        PhysicalInventoryPlan::STATUS_APPROVED => 'Approved',
                        PhysicalInventoryPlan::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalInventoryPlan::STATUS_COMPLETED => 'Completed',
                        PhysicalInventoryPlan::STATUS_CANCELLED => 'Cancelled',
                        default => ucfirst((string) $state),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        PhysicalInventoryPlan::STATUS_COMPLETED => 'success',
                        PhysicalInventoryPlan::STATUS_IN_PROGRESS => 'warning',
                        PhysicalInventoryPlan::STATUS_APPROVED => 'info',
                        PhysicalInventoryPlan::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: PhysicalInventoryPlanModalSchema::components(),
                    footerActions: PhysicalInventoryPlanActions::modalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_STANDARD,
                    extraModalClass: 'owwa-inventory-plan-modal',
                ),
                ActionGroup::make([
                    PhysicalInventoryPlanActions::editAction(),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Archive inventory schedule')
                        ->modalDescription('This schedule will be archived and hidden from the default list. You can restore it later using the filter.')
                        ->action(fn (PhysicalInventoryPlan $record) => $record->delete())
                        ->visible(fn (PhysicalInventoryPlan $record): bool => ! $record->trashed()),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (PhysicalInventoryPlan $record) => $record->restore())
                        ->visible(fn (PhysicalInventoryPlan $record): bool => $record->trashed()),
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
            ->recordAction('view')
            ->emptyStateHeading('No inventory schedules yet')
            ->emptyStateDescription('Create a schedule to plan year-end counts by office and date.');
    }
}
