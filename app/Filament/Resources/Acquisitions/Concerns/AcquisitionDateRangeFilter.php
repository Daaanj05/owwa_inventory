<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class AcquisitionDateRangeFilter
{
    public static function make(string $column, string $label = 'Date range'): Filter
    {
        return Filter::make('date_range')
            ->label($label)
            ->form([
                DatePicker::make('from')
                    ->label('From'),
                DatePicker::make('until')
                    ->label('Until'),
            ])
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
                    $indicators[] = $label.' until '.$data['until'];
                }

                return $indicators;
            });
    }
}
