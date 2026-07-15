<?php

namespace App\Filament\Resources\PropertyActionRequests\Tables;

use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestEmployeeActions;
use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestTableActions;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\PropertyActionRequests\Schemas\PropertyActionRequestInfolistSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\PropertyActionRequestViewPresenter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PropertyActionRequestsTable
{
    public static function configure(Table $table): Table
    {
        /** @var User|null $viewer */
        $viewer = Auth::user();
        $isEmployeeViewer = $viewer?->isEmployee() ?? false;
        $isUnitConsolidatorViewer = $viewer?->isUnitConsolidator() ?? false;

        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('action_type')
                    ->label('Action')
                    ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->actionTypeLabel())
                    ->badge(),
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->reasonLabel()),
                TextColumn::make('property_numbers')
                    ->label(OwwaReferenceLabels::assetIdentifierTableHeader())
                    ->state(fn (PropertyActionRequest $record): string => $record->propertyNumbersLabel())
                    ->placeholder('—'),
                TextColumn::make('lines.issuance.item.category.name')
                    ->label('Category')
                    ->state(function (PropertyActionRequest $record): string {
                        $record->loadMissing('lines.issuance.item.category');

                        $categories = $record->lines
                            ->map(fn ($line) => $line->issuance?->item?->category?->name)
                            ->filter()
                            ->unique()
                            ->values();

                        if ($categories->isEmpty()) {
                            return '—';
                        }

                        if ($categories->count() === 1) {
                            return (string) $categories->first();
                        }

                        return $categories->first().' +'.($categories->count() - 1);
                    })
                    ->placeholder('—'),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->visible(fn (): bool => ! $isEmployeeViewer),
                TextColumn::make('accountableUser.name')
                    ->label('Accountable UC')
                    ->placeholder('—')
                    ->visible(fn (): bool => ! $isUnitConsolidatorViewer),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', ucwords((string) $state, '_')))
                    ->color(fn (?string $state): string => match ($state) {
                        PropertyActionRequest::STATUS_APPROVED, PropertyActionRequest::STATUS_EXECUTED => 'success',
                        PropertyActionRequest::STATUS_REJECTED => 'danger',
                        PropertyActionRequest::STATUS_PENDING_UC, PropertyActionRequest::STATUS_PENDING_SC => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Filed')
                    ->date('M d, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('item_category_id')
                    ->label('Category')
                    ->options(fn (): array => InventoryCategoryOptions::propertyCategoryOptions())
                    ->query(function ($query, array $data) {
                        $categoryId = (int) ($data['value'] ?? 0);

                        if ($categoryId <= 0) {
                            return $query;
                        }

                        return $query->whereHas('lines.issuance.item', fn ($itemQuery) => $itemQuery->where('item_category_id', $categoryId));
                    }),
            ])
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (PropertyActionRequest $record): array => PropertyActionRequestViewPresenter::forRecord($record),
                        PropertyActionRequestInfolistSchema::modalDetailSections(),
                    ),
                    [
                        PropertyActionRequestTableActions::ucApproveFromViewAction(),
                        PropertyActionRequestTableActions::ucRejectFromViewAction(),
                        PropertyActionRequestTableActions::scApproveAction(),
                        PropertyActionRequestTableActions::scRejectAction(),
                        PropertyActionRequestTableActions::scExecuteAction(),
                    ],
                    '5xl',
                    modelLabel: PropertyActionRequestResource::getModelLabel(),
                ),
                PropertyActionRequestTableActions::ucApproveAction(),
                PropertyActionRequestTableActions::ucRejectAction(),
                PropertyActionRequestEmployeeActions::tableActionGroup(),
                PropertyActionRequestEmployeeActions::ucTableActionGroup(),
            ])
            ->recordUrl(null);
    }
}
