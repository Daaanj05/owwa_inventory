<?php

namespace App\Filament\Resources\AiProcurementRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Recommended items';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ?? '—')
                    ->colors([
                        'danger'  => 'High',
                        'warning' => 'Medium',
                        'gray'    => 'Low',
                    ])
                    ->sortable()
                    ->width('90px'),

                Tables\Columns\TextColumn::make('item_name')
                    ->label('Item')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('office_name')
                    ->label('Department / Office')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stock')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state) : '—')
                    ->description(fn () => 'Current')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('avg_monthly_usage')
                    ->label('Avg/mo')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—')
                    ->description(fn () => 'Units/month')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('months_cover')
                    ->label('Cover')
                    ->formatStateUsing(function ($state) {
                        if ($state === null) return '—';
                        $val = (float) $state;
                        $color = $val < 1 ? '🔴' : ($val <= 3 ? '🟡' : '🟢');
                        return $color . ' ' . number_format($val, 1) . ' mo';
                    })
                    ->description(fn () => 'Months of cover')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('suggested_qty_min')
                    ->label('Suggested')
                    ->formatStateUsing(function ($state, $record) {
                        $min = $record->suggested_qty_min;
                        $max = $record->suggested_qty_max;
                        if ($min === null) return '—';
                        if ($min === $max) return (string) $min;
                        return "{$min}–{$max}";
                    })
                    ->description(fn () => 'Qty to reorder')
                    ->alignEnd(),

                Tables\Columns\ToggleColumn::make('include_in_request')
                    ->label('Include'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(70)
                    ->tooltip(fn ($record) => $record->reason)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('priority')
            ->paginated(false)
            ->striped()
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No at-risk items identified')
            ->emptyStateDescription('The AI did not flag any items requiring procurement in this run.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
