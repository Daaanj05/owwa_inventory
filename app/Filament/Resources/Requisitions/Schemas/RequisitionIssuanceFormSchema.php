<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\ProcurementSignatoryName;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionStockSnapshotService;
use App\Support\OfficeSignatoryDefaults;
use App\Support\RequisitionLineDisplay;
use App\Support\SignatorySelect;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\HtmlString;

class RequisitionIssuanceFormSchema
{
    /**
     * @return array{issuance_date: string, lines: array<int, array<string, mixed>>}
     */
    public static function defaultFormState(Requisition $record, bool $remainderOnly = false): array
    {
        $record->loadMissing(['requestedBy.office', 'requestedBy.department']);
        $fulfillment = app(RequisitionFulfillmentService::class);
        $requester = $record->requestedBy;
        $requesterDesignation = self::designationForUser($requester);

        $approvedByName = filled($record->ris_approved_by_name) ? (string) $record->ris_approved_by_name : null;
        $approvedByDesignation = filled($record->ris_approved_by_designation)
            ? (string) $record->ris_approved_by_designation
            : null;
        if (filled($approvedByName) && blank($approvedByDesignation)) {
            $approvedByDesignation = ProcurementSignatoryName::designationFor(
                ProcurementSignatoryName::ROLE_RIS_APPROVED,
                $approvedByName,
            );
        }

        $receivedBy = self::resolveReceivedByUser($record);
        $receivedByDesignation = self::designationForUser($receivedBy);
        [$issuedByName, $issuedByDesignation] = self::resolveIssuedBy($record);

        $payload = $record->pendingIssuePayload();
        $lines = $fulfillment->hasSourceEndorsements($record)
            ? $fulfillment->defaultEndorsementIssueLines($record, $remainderOnly)
            : self::defaultLines($record, $remainderOnly);

        if (is_array($payload['lines'] ?? null) && $payload['lines'] !== []) {
            $lines = self::mergeSavedQuantitiesIntoLines($lines, $payload['lines']);
        }

        return [
            'issuance_date' => is_string($payload['issuance_date'] ?? null)
                ? $payload['issuance_date']
                : now()->toDateString(),
            'ris_requested_by_name' => $requester?->name,
            'ris_requested_by_designation' => $requesterDesignation,
            'ris_requested_by_date' => now()->toDateString(),
            'ris_approved_by_name' => $approvedByName,
            'ris_approved_by_designation' => $approvedByDesignation,
            'ris_approved_by_date' => now()->toDateString(),
            'ris_issued_by_name' => $issuedByName,
            'ris_issued_by_designation' => $issuedByDesignation,
            'ris_issued_by_date' => now()->toDateString(),
            'ris_received_by_name' => $receivedBy?->name ?? $requester?->name,
            'ris_received_by_designation' => $receivedByDesignation ?? $requesterDesignation,
            'ris_received_by_date' => now()->toDateString(),
            'lines' => $lines,
        ];
    }

    /**
     * Issued by is always the signed-in supply custodian account.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveIssuedBy(Requisition $record): array
    {
        $authUser = auth()->user();
        $defaults = OfficeSignatoryDefaults::forIssuance((int) $record->office_id, $authUser instanceof User ? $authUser : null);

        $name = $authUser instanceof User && $authUser->isSupplyCustodian()
            ? $authUser->name
            : $defaults['custodian_printed_name'];

        $designation = $defaults['custodian_designation']
            ?? self::designationForUser($authUser instanceof User ? $authUser : null)
            ?? 'Supply Custodian';

        return [$name, $designation];
    }

    /**
     * Received by: the sole employee when every endorsement belongs to one employee;
     * otherwise the unit consolidator.
     */
    public static function resolveReceivedByUser(Requisition $record): ?User
    {
        $record->loadMissing([
            'requestedBy.office',
            'requestedBy.department',
            'sourceEndorsements.sourceRequisition.requestedBy.office',
            'sourceEndorsements.sourceRequisition.requestedBy.department',
        ]);

        $fulfillment = app(RequisitionFulfillmentService::class);
        if (! $fulfillment->hasSourceEndorsements($record)) {
            return $record->requestedBy;
        }

        $employeeIds = $record->sourceEndorsements
            ->map(fn ($endorsement): int => (int) ($endorsement->requested_by_user_id
                ?? $endorsement->sourceRequisition?->requested_by
                ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($employeeIds->count() === 1) {
            $employee = $record->sourceEndorsements
                ->map(fn ($endorsement) => $endorsement->sourceRequisition?->requestedBy)
                ->first(fn ($user): bool => $user instanceof User && (int) $user->id === (int) $employeeIds->first());

            if ($employee instanceof User) {
                return $employee;
            }
        }

        return $record->requestedBy;
    }

    public static function designationForUser(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $user->loadMissing(['department', 'office']);

        return $user->department?->name ?? $user->office?->name;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $saved
     * @return array<int, array<string, mixed>>
     */
    protected static function mergeSavedQuantitiesIntoLines(array $lines, array $saved): array
    {
        $byEndorsement = [];
        $byItem = [];

        foreach ($saved as $row) {
            if (! is_array($row)) {
                continue;
            }

            $endorsementId = (int) ($row['source_endorsement_id'] ?? 0);
            $itemId = (int) ($row['requisition_item_id'] ?? 0);

            if ($endorsementId > 0) {
                $byEndorsement[$endorsementId] = $row;
            } elseif ($itemId > 0) {
                $byItem[$itemId] = $row;
            }
        }

        return collect($lines)
            ->map(function (array $line) use ($byEndorsement, $byItem): array {
                $endorsementId = (int) ($line['source_endorsement_id'] ?? 0);
                $itemId = (int) ($line['requisition_item_id'] ?? 0);
                $saved = $endorsementId > 0
                    ? ($byEndorsement[$endorsementId] ?? null)
                    : ($byItem[$itemId] ?? null);

                if (! is_array($saved)) {
                    return $line;
                }

                if (array_key_exists('quantity_to_issue', $saved)) {
                    $line['quantity_to_issue'] = (int) $saved['quantity_to_issue'];
                }

                if (array_key_exists('issue_remarks', $saved)) {
                    $line['issue_remarks'] = (string) ($saved['issue_remarks'] ?? '');
                }

                return $line;
            })
            ->values()
            ->all();
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
                        && $stockSnapshot->issuableStockForItem((int) $line->item_id) > 0;
                }

                return $remaining > 0
                    || (int) ($line->quantity_issued ?? 0) === 0;
            })
            ->map(function (RequisitionItem $line) use ($fulfillment, $stockSnapshot): array {
                $remaining = $fulfillment->remainingQuantity($line);
                $stock = $stockSnapshot->issuableStockForItem((int) $line->item_id);

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
            Hidden::make('category_label')->dehydrated(),
            Hidden::make('item_label')->dehydrated(),
            Hidden::make('identifier_value')->dehydrated(),
            self::wrappingCell('category_label'),
            self::wrappingCell('item_label'),
            self::wrappingCell('identifier_value'),
        ];

        if ($remainderOnly) {
            $lineSchema[] = Hidden::make('quantity_issued')->dehydrated();
            $lineSchema[] = self::wrappingCell('quantity_issued', numeric: true);
        } else {
            $lineSchema[] = Hidden::make('quantity_issued');
        }

        $lineSchema = [
            ...$lineSchema,
            Hidden::make('stock_available')->dehydrated(),
            Hidden::make('quantity_requested')->dehydrated(),
            Hidden::make('quantity_remaining')->dehydrated(),
            self::wrappingCell('stock_available', numeric: true),
            self::wrappingCell('quantity_requested', numeric: true),
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
            self::wrappingCell('quantity_remaining', numeric: true),
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
            Hidden::make('employee_name')->dehydrated(),
            Hidden::make('transaction_number')->dehydrated(),
            Hidden::make('category_label')->dehydrated(),
            Hidden::make('item_label')->dehydrated(),
            self::wrappingCell('employee_name'),
            self::wrappingCell('transaction_number'),
            self::wrappingCell('category_label'),
            self::wrappingCell('item_label'),
        ];

        if ($remainderOnly) {
            $lineSchema[] = Hidden::make('quantity_issued')->dehydrated();
            $lineSchema[] = self::wrappingCell('quantity_issued', numeric: true);
        } else {
            $lineSchema[] = Hidden::make('quantity_issued');
        }

        $lineSchema = [
            ...$lineSchema,
            Hidden::make('stock_available')->dehydrated(),
            Hidden::make('quantity_endorsed')->dehydrated(),
            Hidden::make('quantity_remaining')->dehydrated(),
            self::wrappingCell('stock_available', numeric: true),
            self::wrappingCell('quantity_endorsed', numeric: true),
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
            self::wrappingCell('quantity_remaining', numeric: true),
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

        $payload = $record->pendingIssuePayload();
        if (is_array($payload['lines'] ?? null) && $payload['lines'] !== []) {
            $defaultLines = self::mergeSavedQuantitiesIntoLines($defaultLines, $payload['lines']);
        }

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

        $fields[] = Section::make('Signatories')
            ->description('Printed on Appendix 63 RIS. Dates are set automatically to today. The signature line stays blank for a wet signature.')
            ->schema([
                TextInput::make('ris_requested_by_name')
                    ->label('Requested by')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                TextInput::make('ris_requested_by_designation')
                    ->label('Designation')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                Hidden::make('ris_requested_by_date')
                    ->default(now()->toDateString())
                    ->dehydrated(),
                SignatorySelect::make('ris_approved_by_name', ProcurementSignatoryName::ROLE_RIS_APPROVED)
                    ->label('Approved by')
                    ->required()
                    ->live()
                    ->helperText(null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $set('ris_approved_by_designation', ProcurementSignatoryName::designationFor(
                            ProcurementSignatoryName::ROLE_RIS_APPROVED,
                            is_string($state) ? $state : null,
                        ));
                    }),
                SignatorySelect::makeDesignation('ris_approved_by_designation', ProcurementSignatoryName::ROLE_RIS_APPROVED)
                    ->label('Designation')
                    ->required()
                    ->helperText(null),
                Hidden::make('ris_approved_by_date')
                    ->default(now()->toDateString())
                    ->dehydrated(),
                TextInput::make('ris_issued_by_name')
                    ->label('Issued by')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                TextInput::make('ris_issued_by_designation')
                    ->label('Designation')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                Hidden::make('ris_issued_by_date')
                    ->default(now()->toDateString())
                    ->dehydrated(),
                TextInput::make('ris_received_by_name')
                    ->label('Received by')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                TextInput::make('ris_received_by_designation')
                    ->label('Designation')
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                Hidden::make('ris_received_by_date')
                    ->default(now()->toDateString())
                    ->dehydrated(),
            ])
            ->columns(2)
            ->columnSpanFull();

        return $fields;
    }

    protected static function wrappingCell(string $statePath, bool $numeric = false): Placeholder
    {
        return Placeholder::make($statePath.'_display')
            ->hiddenLabel()
            ->content(function (Get $get) use ($statePath): HtmlString {
                $value = $get($statePath);
                $text = filled($value) || $value === 0 || $value === '0'
                    ? e((string) $value)
                    : '—';

                return new HtmlString('<span class="owwa-issue-line-cell">'.$text.'</span>');
            })
            ->extraAttributes($numeric ? ['class' => 'owwa-issue-line-cell-numeric'] : []);
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
