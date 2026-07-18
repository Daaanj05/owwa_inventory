<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Filament\Resources\Acquisitions\Paperwork\Actions\AcquisitionPaperworkActions;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkModalSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\AcquisitionPaperwork;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AcquisitionsTable
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
                TextColumn::make('pr_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AcquisitionPaperwork $record): string => $record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR))
                    ->color(fn (AcquisitionPaperwork $record): string => match (true) {
                        $record->isArchived() => 'gray',
                        $record->isPrApproved() => 'success',
                        $record->isPrPendingApproval() => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('pr_number')
                    ->label('PR No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('pr_date')
                    ->label('PR date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
            ])
            ->filters([
                SelectFilter::make('pr_status')
                    ->label('PR status')
                    ->options([
                        AcquisitionPaperwork::STATUS_DRAFT => 'Draft',
                        AcquisitionPaperwork::STATUS_PENDING_APPROVAL => 'Pending approval',
                        AcquisitionPaperwork::STATUS_APPROVED => 'Approved',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No purchase requests yet')
            ->emptyStateDescription('Start a new PR, then create a PO from an approved request.')
            ->emptyStateIcon('heroicon-o-arrow-down-tray')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: AcquisitionPaperworkModalSchema::components(),
                    footerActions: AcquisitionPaperworkActions::viewModalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_WIDE,
                    extraModalClass: 'owwa-acquisition-paperwork-modal',
                    modalHeading: 'View purchase request',
                ),
                AcquisitionPaperworkActions::configureEditAction(),
                ActionGroup::make([
                    AcquisitionPaperworkActions::archiveAction(),
                    AcquisitionPaperworkActions::restoreAction(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordActionsAlignment('end')
            ->recordUrl(null)
            ->recordAction(fn (AcquisitionPaperwork $record): string => $record->isPrEditable() ? 'edit' : 'view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }
}
