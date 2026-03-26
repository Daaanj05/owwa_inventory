<?php

namespace App\Filament\Resources\ReferenceSeries\Tables;

use App\Services\ReferenceCodeService;
use Filament\Actions\EditAction;
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
                TextColumn::make('name')
                    ->label('Label')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('prefix')
                    ->label('Code letters')
                    ->searchable(),
                TextColumn::make('pattern_description')
                    ->label('Format')
                    ->tooltip(fn ($record) => 'Technical: ' . $record->pattern),
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
                EditAction::make(),
            ])
            ->striped()
            ->emptyStateHeading('No reference formats yet')
            ->emptyStateDescription('Run the Reference Series seeder to create default formats for Issuance, Transfer, Disposal, Requisition, and Acquisition.')
            ->filters([]);
    }
}
