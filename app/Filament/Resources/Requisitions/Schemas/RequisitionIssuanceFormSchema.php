<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Services\InventoryStockService;
use App\Services\RequisitionFulfillmentService;
use App\Support\OfficeSignatoryDefaults;
use App\Support\RequisitionLineDisplay;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class RequisitionIssuanceFormSchema
{
    /**
     * @return array{issuance_date: string, lines: array<int, array<string, mixed>>}
     */
    public static function defaultFormState(Requisition $record, bool $remainderOnly = false): array
    {
        $defaults = OfficeSignatoryDefaults::forIssuance((int) $record->office_id);

        return [
            'issuance_date' => now()->toDateString(),
            'custodian_printed_name' => $defaults['custodian_printed_name'],
            'custodian_designation' => $defaults['custodian_designation'],
            'accounting_staff_printed_name' => $defaults['accounting_staff_printed_name'],
            'lines' => self::defaultLines($record, $remainderOnly),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaultLines(Requisition $record, bool $remainderOnly = false): array
    {
        $record->loadMissing('items.item.category');
        $fulfillment = app(RequisitionFulfillmentService::class);
        $stockService = app(InventoryStockService::class);
        $officeId = (int) $record->office_id;

        return $record->items
            ->filter(function (RequisitionItem $line) use ($fulfillment, $remainderOnly): bool {
                if ($remainderOnly) {
                    return $fulfillment->remainingQuantity($line) > 0;
                }

                return $fulfillment->remainingQuantity($line) > 0
                    || (int) ($line->quantity_issued ?? 0) === 0;
            })
            ->map(function (RequisitionItem $line) use ($fulfillment, $stockService, $officeId): array {
                $remaining = $fulfillment->remainingQuantity($line);
                $stock = $officeId > 0
                    ? max(0, $stockService->getStock((int) $line->item_id, $officeId))
                    : 0;

                return [
                    'requisition_item_id' => $line->id,
                    'category_label' => $line->item?->category?->name ?? '—',
                    'identifier_label' => RequisitionLineDisplay::identifierLabel($line),
                    'identifier_value' => RequisitionLineDisplay::identifierValue($line) ?? '—',
                    'item_label' => $line->item?->name ?? "Item #{$line->item_id}",
                    'quantity_requested' => (int) $line->quantity,
                    'quantity_issued' => (int) ($line->quantity_issued ?? 0),
                    'quantity_remaining' => $remaining,
                    'stock_available' => $stock,
                    'quantity_to_issue' => min($remaining, $stock),
                    'issue_remarks' => $line->issue_remarks ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function issueModalFields(Requisition $record, bool $remainderOnly = false): array
    {
        return [
            DatePicker::make('issuance_date')
                ->label('Issuance date')
                ->required()
                ->default(now()->toDateString()),
            Section::make('Signatories (OWWA export)')
                ->description('Applied to all issuance lines created in this action. Labels follow item category on each export (RSMI / PAR / ICS).')
                ->schema([
                    TextInput::make('custodian_printed_name')
                        ->label('Custodian / issued by')
                        ->maxLength(255),
                    TextInput::make('custodian_designation')
                        ->label('Custodian designation')
                        ->maxLength(255),
                    TextInput::make('issued_to_designation')
                        ->label('Recipient designation')
                        ->maxLength(255)
                        ->helperText('Optional override when the recipient office/department should print on PAR or ICS.'),
                    TextInput::make('accounting_staff_printed_name')
                        ->label('Accounting staff (RSMI)')
                        ->maxLength(255),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Repeater::make('lines')
                ->label('Items to issue')
                ->schema([
                    Hidden::make('requisition_item_id')
                        ->required(),
                    Hidden::make('category_label'),
                    Hidden::make('identifier_label'),
                    Hidden::make('identifier_value'),
                    Hidden::make('item_label'),
                    Hidden::make('quantity_requested'),
                    Hidden::make('quantity_remaining'),
                    Hidden::make('stock_available'),
                    Placeholder::make('category_label_display')
                        ->label('Category')
                        ->content(fn (Get $get): string => (string) ($get('category_label') ?? '')),
                    Placeholder::make('item_label_display')
                        ->label('Item')
                        ->content(fn (Get $get): string => (string) ($get('item_label') ?? ''))
                        ->columnSpan(2),
                    Placeholder::make('identifier_display')
                        ->label(fn (Get $get): string => (string) ($get('identifier_label') ?: 'Identifier'))
                        ->content(fn (Get $get): string => (string) ($get('identifier_value') ?? ''))
                        ->columnSpanFull(),
                    Placeholder::make('quantity_requested_display')
                        ->label('Requested')
                        ->content(fn (Get $get): string => (string) ((int) ($get('quantity_requested') ?? 0))),
                    Placeholder::make('quantity_issued_display')
                        ->label('Already issued')
                        ->content(fn (Get $get): string => (string) ((int) ($get('quantity_issued') ?? 0)))
                        ->visible(fn (): bool => $remainderOnly),
                    Hidden::make('quantity_issued')
                        ->visible(fn (): bool => $remainderOnly),
                    Placeholder::make('quantity_remaining_display')
                        ->label('Remaining')
                        ->content(fn (Get $get): string => (string) ((int) ($get('quantity_remaining') ?? 0))),
                    Placeholder::make('stock_available_display')
                        ->label('Stock available')
                        ->content(fn (Get $get): string => (string) ((int) ($get('stock_available') ?? 0))),
                    TextInput::make('quantity_to_issue')
                        ->label('Qty to issue')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn (Get $get): int => max(0, min(
                            (int) ($get('quantity_remaining') ?? 0),
                            (int) ($get('stock_available') ?? 0),
                        )))
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $qty = (int) $value;
                                $remaining = (int) ($get('quantity_remaining') ?? 0);
                                $requested = (int) ($get('quantity_requested') ?? 0);
                                $stock = (int) ($get('stock_available') ?? 0);

                                if ($qty > $remaining) {
                                    $fail("Quantity to issue cannot exceed remaining requested quantity ({$remaining}).");
                                }

                                if ($qty > $requested) {
                                    $fail("Quantity to issue cannot exceed requested quantity ({$requested}).");
                                }

                                if ($qty > $stock) {
                                    $fail("Quantity to issue cannot exceed available stock ({$stock}).");
                                }
                            },
                        ])
                        ->required()
                        ->default(0),
                    Textarea::make('issue_remarks')
                        ->label('Issue remarks')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->default(self::defaultLines($record, $remainderOnly))
                ->addable(false)
                ->deletable(false)
                ->columns(3)
                ->columnSpanFull(),
        ];
    }
}
