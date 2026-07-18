<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
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
                Section::make('Purchase Order')
                    ->columns([
                        'default' => 2,
                        'lg' => 4,
                    ])
                    ->schema([
                        TextEntry::make('number')->label('PO No.')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->state(fn (PurchaseOrder $record): string => $record->statusLabel()),
                        TextEntry::make('purchaseRequest.pr_number')->label('PR No.')->placeholder('—'),
                        TextEntry::make('po_date')->label('PO Date')->date('M d, Y')->placeholder('—'),
                    ]),
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])->schema([
                    Section::make('Supplier & Procurement')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('supplier_name')->label('Supplier')->placeholder('—'),
                            TextEntry::make('supplier_tin')->label('TIN')->placeholder('—'),
                            TextEntry::make('supplier_address')
                                ->label('Supplier Address')
                                ->placeholder('—')
                                ->columnSpanFull(),
                            TextEntry::make('mode_of_procurement')
                                ->label('Mode Of Procurement')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]),
                    Section::make('Delivery & Payment')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('place_of_delivery')
                                ->label('Place Of Delivery')
                                ->placeholder('—')
                                ->columnSpanFull(),
                            TextEntry::make('delivery_term')->label('Delivery Term')->placeholder('—'),
                            TextEntry::make('date_of_delivery')->label('Date Of Delivery')->date('M d, Y')->placeholder('—'),
                            TextEntry::make('payment_term')
                                ->label('Payment Term')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]),
                ]),
                Section::make('Technical Specification / Remarks')
                    ->schema([
                        TextEntry::make('technical_specifications')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Line Items')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Ordered')->width('8%'),
                                TableColumn::make('Item'),
                                TableColumn::make('PR Qty')->width('10%'),
                                TableColumn::make('PO Qty')->width('10%'),
                                TableColumn::make('Unit Cost')->width('14%'),
                                TableColumn::make('Amount')->width('14%'),
                            ])
                            ->schema([
                                TextEntry::make('is_ordered')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No'),
                                TextEntry::make('item.name')->hiddenLabel()->placeholder('—'),
                                TextEntry::make('pr_quantity')->hiddenLabel(),
                                TextEntry::make('po_quantity')->hiddenLabel(),
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
