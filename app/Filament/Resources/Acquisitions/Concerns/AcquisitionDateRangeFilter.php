<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared From/To date filter for Acquisition, Issuance, and Disposal list tables.
 */
final class AcquisitionDateRangeFilter
{
    public static function make(string $column, string $label = 'Date'): Filter
    {
        return Filter::make('date_range')
            ->label($label)
            ->form([
                DatePicker::make('from')
                    ->label('From')
                    ->native(true)
                    ->inlineLabel(),
                DatePicker::make('until')
                    ->label('To')
                    ->native(true)
                    ->inlineLabel(),
            ])
            ->columns(2)
            ->query(function (Builder $query, array $data) use ($column): Builder {
                return $query
                    ->when(
                        filled($data['from'] ?? null),
                        fn (Builder $query): Builder => $query->whereDate($column, '>=', $data['from']),
                    )
                    ->when(
                        filled($data['until'] ?? null),
                        fn (Builder $query): Builder => $query->whereDate($column, '<=', $data['until']),
                    );
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = $label.' from '.$data['from'];
                }

                if (filled($data['until'] ?? null)) {
                    $indicators[] = $label.' to '.$data['until'];
                }

                return $indicators;
            });
    }

    /**
     * Show From/To on the same toolbar row as Search (right-aligned).
     */
    public static function applyBesideSearch(Table $table): Table
    {
        return $table
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(1)
            ->hiddenFilterIndicators()
            ->extraAttributes(['class' => 'owwa-toolbar-date-range'], merge: true);
    }
}
