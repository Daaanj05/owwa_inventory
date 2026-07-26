<?php

namespace App\Filament\Resources\UacsObjectCodes\Tables;

use App\Filament\Resources\UacsObjectCodes\Schemas\UacsObjectCodeInfolist;
use App\Filament\Resources\UacsObjectCodes\UacsObjectCodeResource;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\UacsObjectCode;
use App\Support\ItemPropertyClass;
use App\Support\OwwaRecordHeroData;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UacsObjectCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('name')
                    ->label('Description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('property_class')
                    ->label('Property class')
                    ->formatStateUsing(fn (?string $state): string => ItemPropertyClass::options()[$state] ?? ($state ?: '—'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (UacsObjectCode $record): string => $record->is_active ? 'Active' : 'Archived')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (UacsObjectCode $record): array => self::heroFor($record),
                        UacsObjectCodeInfolist::modalDetailSections(),
                    ),
                    [
                        OwwaFormModalDefaults::editActionForResource(
                            UacsObjectCodeResource::class,
                            OwwaFormModalDefaults::WIDTH_COMPACT,
                        ),
                    ],
                    modalWidth: OwwaFormModalDefaults::WIDTH_COMPACT,
                    modelLabel: UacsObjectCodeResource::getModelLabel(),
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editActionForResource(
                        UacsObjectCodeResource::class,
                        OwwaFormModalDefaults::WIDTH_COMPACT,
                    ),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive UACS object code')
                        ->modalDescription('This code will be hidden from active lists but kept for historical data. Use the Archived tab to view or restore it.')
                        ->visible(fn (UacsObjectCode $record): bool => $record->is_active)
                        ->action(fn (UacsObjectCode $record) => $record->update(['is_active' => false])),
                    Action::make('unarchive')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (UacsObjectCode $record): bool => ! $record->is_active)
                        ->action(fn (UacsObjectCode $record) => $record->update(['is_active' => true])),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordUrl(null)
            ->recordAction('view')
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ])
            ->defaultSort('code');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function heroFor(UacsObjectCode $record): array
    {
        $hero = OwwaRecordHeroData::make(
            reference: (string) $record->code,
            statusLabel: $record->is_active ? 'Active' : 'Archived',
            statusClass: $record->is_active
                ? 'owwa-pc-status-badge--complete'
                : 'owwa-pc-status-badge--incomplete',
            meta: [],
        );
        $hero['referenceLabel'] = 'Code';

        return $hero;
    }
}
