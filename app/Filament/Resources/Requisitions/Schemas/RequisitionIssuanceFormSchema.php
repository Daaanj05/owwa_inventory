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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
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
        $record->loadMissing(['requestedBy.office', 'requestedBy.department']);

        return [
            'issuance_date' => now()->toDateString(),
            'custodian_printed_name' => $defaults['custodian_printed_name'],
            'custodian_designation' => $defaults['custodian_designation'],
            'issued_to_designation' => $record->requestedBy?->department?->name
                ?? $record->requestedBy?->office?->name,
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
                    'item_label' => $line->item?->name ?? "Item #{$line->item_id}",
                    'identifier_value' => RequisitionLineDisplay::identifierValue($line) ?? '—',
                    'quantity_requested' => (int) $line->quantity,
                    'quantity_issued' => (int) ($line->quantity_issued ?? 0),
                    'quantity_remaining' => $remaining,
                    'stock_at_request' => $line->stock_at_request,
                    'stock_available' => $stock,
                    'is_backordered' => $line->isBackordered(),
                    'quantity_to_issue' => min($remaining, $stock),
                    'issue_remarks' => $line->issue_remarks ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    public static function issueModalFields(Requisition $record, bool $remainderOnly = false): array
    {
        $tableColumns = [
            TableColumn::make('Category'),
            TableColumn::make('Item'),
            TableColumn::make('Identifier'),
            TableColumn::make('Requested'),
        ];

        if ($remainderOnly) {
            $tableColumns[] = TableColumn::make('Issued');
        }

        $tableColumns = [
            ...$tableColumns,
            TableColumn::make('Remaining'),
            TableColumn::make('Stock'),
            TableColumn::make('Qty to issue'),
            TableColumn::make('Issue remarks'),
        ];

        $lineSchema = [
            Hidden::make('requisition_item_id')->required(),
            Hidden::make('stock_at_request'),
            Hidden::make('is_backordered'),
            TextInput::make('category_label')->disabled()->dehydrated(),
            TextInput::make('item_label')->disabled()->dehydrated(),
            TextInput::make('identifier_value')->disabled()->dehydrated(),
            TextInput::make('quantity_requested')->disabled()->dehydrated(),
        ];

        if ($remainderOnly) {
            $lineSchema[] = TextInput::make('quantity_issued')->disabled()->dehydrated();
        } else {
            $lineSchema[] = Hidden::make('quantity_issued');
        }

        $lineSchema = [
            ...$lineSchema,
            TextInput::make('quantity_remaining')->disabled()->dehydrated(),
            TextInput::make('stock_available')->disabled()->dehydrated(),
            TextInput::make('quantity_to_issue')
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
                ->default(0)
                ->live(),
            TextInput::make('issue_remarks')
                ->required(fn (Get $get): bool => self::quantityWasChanged($get))
                ->helperText(fn (Get $get): ?string => self::quantityWasChanged($get)
                    ? 'Required when the quantity to issue differs from the requested quantity.'
                    : null),
        ];

        return [
            DatePicker::make('issuance_date')
                ->label('Issuance date')
                ->required()
                ->default(now()->toDateString())
                ->disabled()
                ->dehydrated(),
            Section::make('Signatories')
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
                ->table($tableColumns)
                ->compact()
                ->schema($lineSchema)
                ->default(self::defaultLines($record, $remainderOnly))
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columnSpanFull(),
        ];
    }

    protected static function quantityWasChanged(Get $get): bool
    {
        $qtyToIssue = (int) ($get('quantity_to_issue') ?? 0);
        $requested = (int) ($get('quantity_requested') ?? 0);
        $remaining = (int) ($get('quantity_remaining') ?? 0);
        $baseline = $remaining > 0 ? $remaining : $requested;

        return $qtyToIssue !== $baseline;
    }
}
