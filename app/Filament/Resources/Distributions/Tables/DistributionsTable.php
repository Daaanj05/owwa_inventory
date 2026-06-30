<?php

namespace App\Filament\Resources\Distributions\Tables;

use App\Filament\Resources\Distributions\Actions\DistributionViewActions;
use App\Filament\Resources\Distributions\Schemas\DistributionInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Redirect;

class DistributionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->sortable(),
                TextColumn::make('distributedTo.name')
                    ->label('Distributed to')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('distribution_date')
                    ->label('Date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('requisition.reference_code')
                    ->label('Request ref.')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('distributedBy.name')
                    ->label('Distributed by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('distribution_date', 'desc')
            ->filters([
                SelectFilter::make('distributed_to')
                    ->label('Employee')
                    ->relationship('distributedTo', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All employees'),
            ])
            ->emptyStateHeading('No distributions yet')
            ->emptyStateDescription('Distribute items to Employees from this page.')
            ->emptyStateIcon('heroicon-o-gift')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn ($record): array => OwwaTransactionViewPresenter::forDistribution($record),
                        DistributionInfolist::modalDetailSections(),
                    ),
                    [
                        DistributionViewActions::exportOwwaAction(),
                    ],
                    '5xl',
                ),
                Action::make('exportOwwa')
                    ->label('Export OWWA form')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn ($record) => Redirect::away(route('owwa.export.distribution', $record))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }
}
