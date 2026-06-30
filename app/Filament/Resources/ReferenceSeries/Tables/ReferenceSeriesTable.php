<?php

namespace App\Filament\Resources\ReferenceSeries\Tables;

use App\Filament\Resources\ReferenceSeries\Schemas\ReferenceSeriesInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Services\ReferenceCodeService;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReferenceSeriesTable
{
    public static function configure(Table $table): Table
    {
        $service = app(ReferenceCodeService::class);

        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Transaction type')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => $record->archived_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('name')
                    ->label('Label')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('prefix')
                    ->label('Code letters')
                    ->state(fn ($record): string => $record->isTransactionSeries() ? '—' : (string) $record->prefix)
                    ->searchable(),
                TextColumn::make('pattern_description')
                    ->label('Format')
                    ->tooltip(fn ($record) => 'Technical: '.$record->pattern),
                TextColumn::make('next_sequence')
                    ->label('Next number')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('reset_period')
                    ->label('Resets')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'yearly' => 'Yearly',
                        'monthly' => 'Monthly',
                        'daily' => 'Daily',
                        default => 'Never',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'yearly' => 'success',
                        'monthly' => 'info',
                        'daily' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('next_code')
                    ->label('Next reference number')
                    ->state(fn ($record) => $service->previewNext($record->type))
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
            ])
            ->defaultSort('type')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn ($record): array => OwwaTransactionViewPresenter::forAdminRecord($record, 'Format'),
                        ReferenceSeriesInfolist::modalDetailSections(),
                    ),
                    [
                        OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    ],
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Archive reference format')
                        ->modalDescription('This format will be hidden from active lists but kept for history. Use the Archived tab to view or restore it.')
                        ->visible(fn ($record) => $record->archived_at === null)
                        ->action(fn ($record) => $record->update(['archived_at' => now()])),
                    Action::make('unarchive')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn ($record) => $record->archived_at !== null)
                        ->action(fn ($record) => $record->update(['archived_at' => null])),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordUrl(null)
            ->recordAction('view')
            ->striped()
            ->emptyStateHeading('No reference formats yet')
            ->emptyStateDescription('Run the Reference Series seeder to create default formats for Issuance, Transfer, Disposal, Requisition, and Acquisition.')
            ->filters([]);
    }
}
