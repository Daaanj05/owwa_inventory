<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use DateTimeInterface;
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
                    ->inlineLabel()
                    ->live(onBlur: true),
                DatePicker::make('until')
                    ->label('To')
                    ->native(true)
                    ->inlineLabel()
                    ->live(onBlur: true),
            ])
            ->columns(2)
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $from = self::normalizeDate($data['from'] ?? null);
                $until = self::normalizeDate($data['until'] ?? null);

                if ($from !== null && $until !== null && $from > $until) {
                    return $query;
                }

                return $query
                    ->when(
                        $from !== null,
                        fn (Builder $query): Builder => $query->whereDate($column, '>=', $from),
                    )
                    ->when(
                        $until !== null,
                        fn (Builder $query): Builder => $query->whereDate($column, '<=', $until),
                    );
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];
                $from = self::normalizeDate($data['from'] ?? null);
                $until = self::normalizeDate($data['until'] ?? null);

                if ($from !== null) {
                    $indicators[] = $label.' from '.$from;
                }

                if ($until !== null) {
                    $indicators[] = $label.' to '.$until;
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

    public static function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : substr($value, 0, 10);
    }
}
