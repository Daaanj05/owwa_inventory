<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Tables;

use App\Filament\Resources\PhysicalInventoryPlans\Actions\PhysicalInventoryPlanActions;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Resources\PhysicalInventoryPlans\Schemas\PhysicalInventoryPlanModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalInventoryPlan;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PhysicalInventoryPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deselectAllRecordsWhenFiltered(false)
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::inventoryPlan())
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
                    ->state(fn (PhysicalInventoryPlan $record): HtmlString => new HtmlString(
                        view('filament.resources.physical-inventory-plans.partials.schedule-progress', [
                            'record' => $record,
                        ])->render()
                    ))
                    ->html(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PhysicalInventoryPlan $record): string => $record->statusLabel())
                    ->color(fn (PhysicalInventoryPlan $record): string => $record->statusColor()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: PhysicalInventoryPlanModalSchema::components(),
                    footerActions: PhysicalInventoryPlanActions::modalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_STANDARD,
                    extraModalClass: 'owwa-inventory-plan-modal',
                    modelLabel: PhysicalInventoryPlanResource::getModelLabel(),
                ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Archive selected')
                        ->visible(fn (): bool => ! (bool) ($table->getLivewire()->showingArchived ?? false)),
                    RestoreBulkAction::make()
                        ->visible(fn (): bool => (bool) ($table->getLivewire()->showingArchived ?? false)),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view')
            ->emptyStateHeading('No inventory schedules yet')
            ->emptyStateDescription('Create a schedule to plan year-end counts for all offices by date.');
    }
}
