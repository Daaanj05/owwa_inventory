<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Tables;

use App\Filament\Resources\Acquisitions\Concerns\AcquisitionDateRangeFilter;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Actions\InspectionAcceptanceReportActions;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas\InspectionAcceptanceReportInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\InspectionAcceptanceReport;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InspectionAcceptanceReportsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->extraAttributes(['class' => 'owwa-acquisition-docs-table'])
            ->columns([
                TextColumn::make('number')
                    ->label('IAR No.')
                    ->placeholder('Draft')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->state(fn (InspectionAcceptanceReport $record): string => $record->statusLabel()),
                TextColumn::make('purchaseOrder.number')
                    ->label('PO No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('iar_date')
                    ->label('IAR date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('invoice_number')
                    ->label('Invoice No.')
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
            ])
            ->filters([
                AcquisitionDateRangeFilter::make('iar_date', 'Date'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No inspection reports yet')
            ->emptyStateDescription('Create an IAR by choosing an approved purchase order.')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: InspectionAcceptanceReportInfolist::modalComponents(),
                    footerActions: InspectionAcceptanceReportActions::viewModalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_WIDE,
                    extraModalClass: 'owwa-acquisition-paperwork-modal',
                    modalHeading: 'View Inspection & Acceptance',
                ),
                InspectionAcceptanceReportActions::configureEditAction(),
                ActionGroup::make([
                    InspectionAcceptanceReportActions::archiveAction(),
                    InspectionAcceptanceReportActions::restoreAction(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordActionsAlignment('end')
            ->recordUrl(null)
            ->recordAction(fn (InspectionAcceptanceReport $record): string => $record->isEditable() ? 'edit' : 'view');

        return AcquisitionDateRangeFilter::applyBesideSearch(
            OwwaTableDefaults::hideRedundantToolbarIcons($table),
        );
    }
}
