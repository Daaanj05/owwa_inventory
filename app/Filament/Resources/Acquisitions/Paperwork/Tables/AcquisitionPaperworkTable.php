<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Tables;

use App\Filament\Resources\Acquisitions\Paperwork\Actions\AcquisitionPaperworkActions;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Models\AcquisitionPaperwork;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AcquisitionPaperworkTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::acquisitionPaperwork())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phase')
                    ->badge()
                    ->formatStateUsing(fn (AcquisitionPaperwork $record): string => $record->phaseLabel())
                    ->color(fn (AcquisitionPaperwork $record): string => match ($record->phase) {
                        AcquisitionPaperwork::PHASE_IAR => 'success',
                        AcquisitionPaperwork::PHASE_PO => 'warning',
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
                TextColumn::make('pr_date')
                    ->label('PR date')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('phase')
                    ->options([
                        AcquisitionPaperwork::PHASE_PR => 'Purchase request',
                        AcquisitionPaperwork::PHASE_PO => 'Purchase order',
                        AcquisitionPaperwork::PHASE_IAR => 'Inspection & acceptance',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No acquisition paperwork yet')
            ->emptyStateDescription('Start PR / PO / IAR paperwork to fill and export OWWA forms.')
            ->recordActions([
                tap(
                    ConfiguresOwwaViewAction::make(
                        schema: AcquisitionPaperworkModalSchema::components(),
                        footerActions: AcquisitionPaperworkActions::viewModalFooterActions(),
                        modalWidth: '5xl',
                        extraModalClass: 'owwa-acquisition-paperwork-modal',
                        modalHeading: 'View acquisition',
                    ),
                    fn (\Filament\Actions\ViewAction $action) => $action->registerModalActions(
                        AcquisitionPaperworkActions::hiddenPhaseViewActionsForStepper(),
                    ),
                ),
                ActionGroup::make([
                    AcquisitionPaperworkActions::configureEditAction(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }
}
