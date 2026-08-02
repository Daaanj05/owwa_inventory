<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas;

use App\Models\InspectionAcceptanceReport;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InspectionAcceptanceReportInfolist
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function modalComponents(): array
    {
        return self::configure(\Filament\Schemas\Schema::make())->getComponents();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Inspection & Acceptance')
                    ->columns([
                        'default' => 2,
                        'lg' => 4,
                    ])
                    ->schema([
                        TextEntry::make('number')->label('IAR No.')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->state(fn (InspectionAcceptanceReport $record): string => $record->statusLabel()),
                        TextEntry::make('purchaseOrder.number')->label('PO No.')->placeholder('—'),
                        TextEntry::make('purchaseOrder.purchaseRequest.pr_number')->label('PR No.')->placeholder('—'),
                    ]),
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])->schema([
                    Section::make('Document Dates')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('iar_date')->label('IAR Date')->date('M d, Y')->placeholder('—'),
                            TextEntry::make('invoice_number')->label('Invoice No.')->placeholder('—'),
                            TextEntry::make('invoice_date')->label('Invoice Date')->date('M d, Y')->placeholder('—'),
                            TextEntry::make('date_inspected')->label('Inspection Date')->date('M d, Y')->placeholder('—'),
                            TextEntry::make('date_received')
                                ->label('Receive Date')
                                ->date('M d, Y')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]),
                    Section::make('Inspection & Custody')
                        ->schema([
                            TextEntry::make('inspection_officer_name')
                                ->label('Inspection Officer')
                                ->placeholder('—'),
                            TextEntry::make('custodian_name')
                                ->label('Supply And/Or Property Custodian')
                                ->placeholder('—'),
                        ]),
                ]),
                Section::make('Line Items')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Item'),
                                TableColumn::make('Requested Qty'),
                                TableColumn::make('Ordered Qty'),
                                TableColumn::make('Received Qty'),
                                TableColumn::make('Unit Cost'),
                                TableColumn::make('Amount'),
                            ])
                            ->schema([
                                TextEntry::make('item.name')->hiddenLabel()->placeholder('—'),
                                TextEntry::make('pr_quantity')->hiddenLabel(),
                                TextEntry::make('po_quantity')->hiddenLabel(),
                                TextEntry::make('iar_quantity')->hiddenLabel(),
                                TextEntry::make('unit_cost')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn ($state): string => $state !== null ? '₱'.number_format((float) $state, 2) : '—'),
                                TextEntry::make('amount')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn ($state): string => $state !== null ? '₱'.number_format((float) $state, 2) : '—'),
                            ]),
                    ]),
            ]);
    }
}
