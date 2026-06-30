<?php

namespace App\Filament\Resources\ReferenceSeries\Schemas;

use App\Filament\Support\AdminRecordInfolist;
use App\Models\ReferenceSeries;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class ReferenceSeriesInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Reference format')
                ->columns(2)
                ->schema([
                    TextEntry::make('type')
                        ->label('Transaction type')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('name')
                        ->label('Label')
                        ->placeholder('—'),
                    TextEntry::make('prefix')
                        ->label('Code letters')
                        ->visible(fn (ReferenceSeries $record): bool => ! $record->isTransactionSeries())
                        ->placeholder('—'),
                    TextEntry::make('pattern')
                        ->label('Format (advanced)')
                        ->fontFamily('mono'),
                    TextEntry::make('next_sequence')
                        ->label('Next number'),
                    TextEntry::make('reset_period')
                        ->label('Resets')
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            ReferenceSeries::RESET_YEARLY => 'Yearly',
                            ReferenceSeries::RESET_MONTHLY => 'Monthly',
                            ReferenceSeries::RESET_DAILY => 'Daily',
                            default => 'Never',
                        })
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            ReferenceSeries::RESET_YEARLY => 'success',
                            ReferenceSeries::RESET_MONTHLY => 'info',
                            ReferenceSeries::RESET_DAILY => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('last_generated_at')
                        ->label('Last used on')
                        ->date('M j, Y')
                        ->placeholder('—'),
                    AdminRecordInfolist::archivedStatusEntry(),
                ]),
        ];
    }
}
