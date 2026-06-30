<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Filament\Resources\Acquisitions\Paperwork\Actions\AcquisitionPaperworkActions;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Support\OwwaReferenceLabels;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AcquisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::acquisitionPaperwork())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('workflow_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AcquisitionPaperwork $record): string => $record->workflowStatusLabel())
                    ->color(fn (AcquisitionPaperwork $record): string => match (true) {
                        $record->isReceived() => 'success',
                        $record->isIarApproved() => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('pr_number')
                    ->label('PR No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('po_number')
                    ->label('PO No.')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('iar_number')
                    ->label('IAR No.')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->date('M d, Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('workflow')
                    ->label('Status')
                    ->options([
                        'in_progress' => 'In progress',
                        'received' => 'Received',
                    ])
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === 'received') {
                            return $query->whereNotNull('received_at');
                        }

                        if (($data['value'] ?? null) === 'in_progress') {
                            return $query->whereNull('received_at');
                        }

                        return $query;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No acquisitions yet')
            ->emptyStateDescription('Start a new acquisition to fill PR, PO, and IAR paperwork.')
            ->emptyStateIcon('heroicon-o-arrow-down-tray')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: AcquisitionPaperworkModalSchema::components(),
                    footerActions: AcquisitionPaperworkActions::modalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_WIDE,
                    extraModalClass: 'owwa-acquisition-paperwork-modal',
                ),
                OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
                    ->label('')
                    ->tableIcon(null)
                    ->extraAttributes(['class' => 'sr-only'])
                    ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal owwa-record-modal owwa-acquisition-paperwork-modal'])
                    ->extraModalFooterActions(AcquisitionPaperworkActions::modalFooterActions()),
                AcquisitionPaperworkActions::viewPrAction()->extraAttributes(['class' => 'sr-only']),
                AcquisitionPaperworkActions::viewPoAction()->extraAttributes(['class' => 'sr-only']),
                AcquisitionPaperworkActions::viewIarAction()->extraAttributes(['class' => 'sr-only']),
            ])
            ->recordUrl(null)
            ->recordAction(fn (AcquisitionPaperwork $record): string => $record->isReceived() ? 'view' : 'edit');
    }
}
