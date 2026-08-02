<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Tables;

use App\Filament\Resources\Acquisitions\Concerns\AcquisitionDateRangeFilter;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\PurchaseOrder;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->extraAttributes(['class' => 'owwa-acquisition-docs-table'])
            ->columns([
                TextColumn::make('number')
                    ->label('PO No.')
                    ->placeholder('Draft')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->state(fn (PurchaseOrder $record): string => $record->statusLabel()),
                TextColumn::make('purchaseRequest.pr_number')
                    ->label('PR No.')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('po_date')
                    ->label('PO date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PurchaseOrder::STATUS_DRAFT => 'Draft',
                        PurchaseOrder::STATUS_PENDING_APPROVAL => 'Pending approval',
                        PurchaseOrder::STATUS_APPROVED => 'Approved',
                    ]),
                AcquisitionDateRangeFilter::make('po_date', 'PO date'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No purchase orders yet')
            ->emptyStateDescription('Create a PO by choosing an approved purchase request.')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    schema: PurchaseOrderInfolist::modalComponents(),
                    footerActions: PurchaseOrderActions::viewModalFooterActions(),
                    modalWidth: OwwaFormModalDefaults::WIDTH_WIDE,
                    extraModalClass: 'owwa-acquisition-paperwork-modal owwa-po-modal',
                    modalHeading: 'View Purchase Order',
                ),
                PurchaseOrderActions::configureEditAction(),
                ActionGroup::make([
                    PurchaseOrderActions::archiveAction(),
                    PurchaseOrderActions::restoreAction(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordActionsAlignment('end')
            ->recordUrl(null)
            ->recordAction(fn (PurchaseOrder $record): string => $record->isEditable() ? 'edit' : 'view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }
}
