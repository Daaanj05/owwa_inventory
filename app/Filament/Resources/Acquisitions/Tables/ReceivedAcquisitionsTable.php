<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Filament\Resources\Acquisitions\Concerns\AcquisitionDateRangeFilter;
use App\Filament\Resources\Acquisitions\Paperwork\Actions\AcquisitionPaperworkActions;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaTableDefaults;
use App\Support\OwwaReferenceLabels;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceivedAcquisitionsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->extraAttributes(['class' => 'owwa-acquisition-docs-table'])
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::acquisitionPaperwork())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('pr_number')
                    ->label('PR No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('purchaseOrder.number')
                    ->label('PO No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('purchaseOrder.inspectionAcceptanceReport.number')
                    ->label('IAR No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('M d, Y g:i A')
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
            ])
            ->filters([
                AcquisitionDateRangeFilter::make('received_at', 'Received date'),
            ])
            ->defaultSort('received_at', 'desc')
            ->emptyStateHeading('No received acquisitions yet')
            ->emptyStateDescription('Completed PR → PO → IAR cases appear here after custodian receipt.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: AcquisitionPaperworkModalSchema::receivedComponents(),
                    footerActions: [
                        AcquisitionPaperworkActions::printUnitQrLabelsAction(),
                    ],
                    modalWidth: OwwaFormModalDefaults::WIDTH_WIDE,
                    extraModalClass: 'owwa-acquisition-paperwork-modal',
                    modalHeading: 'View received acquisition',
                ),
            ])
            ->recordActionsAlignment('end')
            ->recordUrl(null)
            ->recordAction('view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }
}
