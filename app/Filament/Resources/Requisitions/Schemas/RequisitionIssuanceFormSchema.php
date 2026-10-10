<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionStockSnapshotService;
use App\Support\OfficeSignatoryDefaults;
use App\Support\RequisitionLineDisplay;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;

class RequisitionIssuanceFormSchema
{
    /**
     * @return array{issuance_date: string, lines: array<int, array<string, mixed>>}
     */
    public static function defaultFormState(Requisition $record, bool $remainderOnly = false): array
    {
        $defaults = OfficeSignatoryDefaults::forIssuance((int) $record->office_id);
        $record->loadMissing(['requestedBy.office', 'requestedBy.department']);
        $fulfillment = app(RequisitionFulfillmentService::class);

        return [
            'issuance_date' => now()->toDateString(),
            'custodian_printed_name' => $defaults['custodian_printed_name'],
            'custodian_designation' => $defaults['custodian_designation'],
            'issued_to_designation' => $record->requestedBy?->department?->name
                ?? $record->requestedBy?->office?->name,
            'accounting_staff_printed_name' => $defaults['accounting_staff_printed_name'],
            'lines' => $fulfillment->hasSourceEndorsements($record)
                ? $fulfillment->defaultEndorsementIssueLines($record, $remainderOnly)
                : self::defaultLines($record, $remainderOnly),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaultLines(Requisition $record, bool $remainderOnly = false): array
    {
        $record->loadMissing('items.item.category');
        $fulfillment = app(RequisitionFulfillmentService::class);
        $stockSnapshot = app(RequisitionStockSnapshotService::class);

        return $record->items
            ->filter(function (RequisitionItem $line) use ($fulfillment, $remainderOnly, $stockSnapshot): bool {
                $remaining = $fulfillment->remainingQuantity($line);

                if ($remainderOnly) {
                    return $remaining > 0
                        && $stockSnapshot->regionalStockForItem((int) $line->item_id) > 0;
                }

                return $remaining > 0
                    || (int) ($line->quantity_issued ?? 0) === 0;
            })
            ->map(function (RequisitionItem $line) use ($fulfillment, $stockSnapshot): array {
                $remaining = $fulfillment->remainingQuantity($line);
                $stock = $stockSnapshot->regionalStockForItem((int) $line->item_id);

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
        $fulfillment = app(RequisitionFulfillmentService::class);
        $usesEndorsements = $fulfillment->hasSourceEndorsements($record);

        if ($usesEndorsements) {
            return self::endorsementIssueModalFields($record, $remainderOnly);
        }

        return self::consolidatedIssueModalFields($record, $remainderOnly);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected static function consolidatedIssueModalFields(Requisition $record, bool $remainderOnly): array
    {
        $tableColumns = [
            self::textTableColumn('Category', '12%'),
            self::textTableColumn('Item', '18%'),
            self::textTableColumn('Identifier', '12%'),
        ];

        if ($remainderOnly) {
            $tableColumns[] = self::numericTableColumn('Issued', '5rem');
        }

        $tableColumns = [
            ...$tableColumns,
            self::numericTableColumn('Stock', '5rem'),
            self::numericTableColumn('Requested', '5.5rem'),
            self::numericTableColumn('Qty to issue', '6rem'),
            self::numericTableColumn('Remaining', '5.5rem'),
            self::textTableColumn('Issue remarks', '16%'),
        ];

        $lineSchema = [
            Hidden::make('requisition_item_id')->required(),
            Hidden::make('stock_at_request'),
            Hidden::make('is_backordered'),
            TextInput::make('category_label')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('item_label')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('identifier_value')->hiddenLabel()->disabled()->dehydrated(),
        ];

        if ($remainderOnly) {
            $lineSchema[] = TextInput::make('quantity_issued')->hiddenLabel()->disabled()->dehydrated();
        } else {
            $lineSchema[] = Hidden::make('quantity_issued');
        }

        $lineSchema = [
            ...$lineSchema,
            TextInput::make('stock_available')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('quantity_requested')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('quantity_to_issue')
                ->hiddenLabel()
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
            TextInput::make('quantity_remaining')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('issue_remarks')
                ->hiddenLabel()
                ->placeholder('Required if qty differs')
                ->required(fn (Get $get): bool => self::quantityWasChanged($get)),
        ];

        return self::wrapIssueModal($record, $remainderOnly, $tableColumns, $lineSchema);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected static function endorsementIssueModalFields(Requisition $record, bool $remainderOnly): array
    {
        $tableColumns = [
            self::textTableColumn('Employee', '12%'),
            self::textTableColumn('Request', '10%'),
            self::textTableColumn('Category', '10%'),
            self::textTableColumn('Item', '14%'),
        ];

        if ($remainderOnly) {
            $tableColumns[] = self::numericTableColumn('Issued', '5rem');
        }

        $tableColumns = [
            ...$tableColumns,
            self::numericTableColumn('Stock', '5rem'),
            self::numericTableColumn('Endorsed', '5.5rem'),
            self::numericTableColumn('Qty to issue', '6rem'),
            self::numericTableColumn('Remaining', '5.5rem'),
            self::textTableColumn('Issue remarks', '14%'),
        ];

        $lineSchema = [
            Hidden::make('source_endorsement_id')->required(),
            TextInput::make('employee_name')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('transaction_number')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('category_label')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('item_label')->hiddenLabel()->disabled()->dehydrated(),
        ];

        if ($remainderOnly) {
            $lineSchema[] = TextInput::make('quantity_issued')->hiddenLabel()->disabled()->dehydrated();
        } else {
            $lineSchema[] = Hidden::make('quantity_issued');
        }

        $lineSchema = [
            ...$lineSchema,
            TextInput::make('stock_available')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('quantity_endorsed')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('quantity_to_issue')
                ->hiddenLabel()
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
                        $endorsed = (int) ($get('quantity_endorsed') ?? 0);
                        $stock = (int) ($get('stock_available') ?? 0);

                        if ($qty > $remaining) {
                            $fail("Quantity to issue cannot exceed remaining endorsed quantity ({$remaining}).");
                        }

                        if ($qty > $endorsed) {
                            $fail("Quantity to issue cannot exceed endorsed quantity ({$endorsed}).");
                        }

                        if ($qty > $stock) {
                            $fail("Quantity to issue cannot exceed available stock ({$stock}).");
                        }
                    },
                ])
                ->required()
                ->default(0)
                ->live(),
            TextInput::make('quantity_remaining')->hiddenLabel()->disabled()->dehydrated(),
            TextInput::make('issue_remarks')
                ->hiddenLabel()
                ->placeholder('Required if qty differs')
                ->required(fn (Get $get): bool => self::endorsementQuantityWasChanged($get)),
        ];

        return self::wrapIssueModal($record, $remainderOnly, $tableColumns, $lineSchema, true);
    }

    /**
     * @param  array<int, TableColumn>  $tableColumns
     * @param  array<int, \Filament\Forms\Components\Component>  $lineSchema
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected static function wrapIssueModal(
        Requisition $record,
        bool $remainderOnly,
        array $tableColumns,
        array $lineSchema,
        bool $usesEndorsements = false,
    ): array {
        $fulfillment = app(RequisitionFulfillmentService::class);
        $defaultLines = $usesEndorsements
            ? $fulfillment->defaultEndorsementIssueLines($record, $remainderOnly)
            : self::defaultLines($record, $remainderOnly);

        $fields = [
            Hidden::make('issuance_date')
                ->default(now()->toDateString())
                ->dehydrated(),
            Placeholder::make('issuance_date_display')
                ->label('Issuance date')
                ->content(fn (Get $get): string => filled($get('issuance_date'))
                    ? Carbon::parse((string) $get('issuance_date'))->format('M d, Y')
                    : now()->format('M d, Y')),
        ];

        if ($usesEndorsements) {
            $record->loadMissing('items');
            $mergedTotal = (int) $record->items->sum('quantity');
            $fields[] = Placeholder::make('ris_summary')
                ->label('Consolidated RIS')
                ->content("One RIS ({$record->reference_code}) with {$mergedTotal} total endorsed units. Issue per employee below; paperwork stays merged.")
                ->columnSpanFull();
        }

        $fields[] = Section::make('Signatories')
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
                    ->helperText('Printed on PAR/ICS as received-by designation (Unit Consolidator on consolidated RIS).'),
            ])
            ->columns(2)
            ->columnSpanFull();

        $fields[] = Repeater::make('lines')
            ->label($usesEndorsements ? 'Issue per employee' : 'Items to issue')
            ->extraAttributes(['class' => 'owwa-requisition-issue-lines-repeater owwa-line-table'])
            ->table($tableColumns)
            ->compact()
            ->schema($lineSchema)
            ->default($defaultLines)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull();

        return $fields;
    }

    protected static function textTableColumn(string $label, string $width): TableColumn
    {
        return TableColumn::make($label)
            ->alignment(Alignment::Start)
            ->verticalAlignment(VerticalAlignment::Center)
            ->width($width);
    }

    protected static function numericTableColumn(string $label, string $width): TableColumn
    {
        return TableColumn::make($label)
            ->alignment(Alignment::Center)
            ->verticalAlignment(VerticalAlignment::Center)
            ->width($width);
    }

    protected static function quantityWasChanged(Get $get): bool
    {
        $qtyToIssue = (int) ($get('quantity_to_issue') ?? 0);
        $requested = (int) ($get('quantity_requested') ?? 0);
        $remaining = (int) ($get('quantity_remaining') ?? 0);
        $baseline = $remaining > 0 ? $remaining : $requested;

        return $qtyToIssue !== $baseline;
    }

    protected static function endorsementQuantityWasChanged(Get $get): bool
    {
        $qtyToIssue = (int) ($get('quantity_to_issue') ?? 0);
        $remaining = (int) ($get('quantity_remaining') ?? 0);

        return $qtyToIssue !== $remaining;
    }
}
