<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ReferenceSeries;
use App\Models\Requisition;
use App\Services\ReferenceCodeService;
use App\Services\RequisitionPurchaseRequestService;
use App\Support\AcquisitionPaperworkViewPresenter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class AcquisitionPaperworkForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Placeholder::make('phase_pending_notice')
                    ->label('')
                    ->content('Awaiting offline approval. Review the form, then use Record Offline Approval below.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => $operation === 'edit'
                        && AcquisitionPaperworkViewPresenter::isCurrentPhasePending($record))
                    ->columnSpanFull(),
                Section::make('Purchase request')
                    ->description('Fill PR details, then save and submit for export. After offline sign-off, record approval.')
                    ->columns(2)
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => ($operation === 'create'
                            || AcquisitionPaperworkViewPresenter::currentEditPhase($record) === AcquisitionPaperwork::PHASE_PR)
                        && ! AcquisitionPaperworkViewPresenter::isCurrentPhasePending($record))
                    ->schema([
                        Placeholder::make('item_category_display')
                            ->label('Item category')
                            ->content(function (?AcquisitionPaperwork $record): string {
                                if (filled($record?->itemCategory?->name)) {
                                    return (string) $record->itemCategory->name;
                                }

                                $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

                                return ItemCategory::query()->find($categoryId)?->name ?? '—';
                            }),
                        Hidden::make('item_category_id')
                            ->default(fn (): mixed => SyncsActiveItemCategory::resolveCategoryIdFromContext())
                            ->dehydrated()
                            ->required(),
                        Placeholder::make('office_section_display')
                            ->label('Office/Section')
                            ->content(fn (): string => app(\App\Support\SupplyOfficeResolver::class)->resolveOfficeName() ?? '—'),
                        Hidden::make('office_id')
                            ->default(fn (): ?int => app(\App\Support\SupplyOfficeResolver::class)->resolve())
                            ->dehydrated()
                            ->required(),
                        Hidden::make('requesting_office_id')
                            ->default(fn (): ?int => app(\App\Support\SupplyOfficeResolver::class)->resolve())
                            ->dehydrated()
                            ->required(),
                    ]),
                Section::make('Purchase request')
                    ->description(fn (string $operation, ?AcquisitionPaperwork $record): ?string => $operation === 'create' || self::isPrEditable($record)
                        ? 'Fill PR header and line items, then save and submit for export.'
                        : 'PR submitted — awaiting approval.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => ($operation === 'create'
                            || ! ($record?->isPrApproved() ?? false))
                        && ! AcquisitionPaperworkViewPresenter::isCurrentPhasePending($record))
                    ->columns(2)
                    ->schema(self::prHeaderFields()),
                Section::make('Line items')
                    ->description('Add items and quantities for this purchase request. Unit costs are entered when the purchase order is prepared.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => ($operation === 'create'
                            || ! ($record?->isPrApproved() ?? false))
                        && ! AcquisitionPaperworkViewPresenter::isCurrentPhasePending($record))
                    ->columns(2)
                    ->schema([
                        self::prLineItemsRepeater($scopeActive)->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function showsOfficeAsDisplay(?AcquisitionPaperwork $record): bool
    {
        return true;
    }

    /**
     * @return array<int, Hidden|Placeholder|Select>
     */
    protected static function officeFieldComponents(): array
    {
        return [];
    }

    protected static function isReceived(?AcquisitionPaperwork $record): bool
    {
        return $record?->isReceived() ?? false;
    }

    protected static function isPrEditable(?AcquisitionPaperwork $record): bool
    {
        if ($record === null) {
            return true;
        }

        return $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT
            && ! self::isReceived($record);
    }

    protected static function isPoEditable(?AcquisitionPaperwork $record): bool
    {
        return $record !== null
            && $record->isPrApproved()
            && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT
            && ! self::isReceived($record);
    }

    protected static function isIarEditable(?AcquisitionPaperwork $record): bool
    {
        return $record !== null
            && $record->isPoApproved()
            && $record->iar_status === AcquisitionPaperwork::STATUS_DRAFT
            && ! self::isReceived($record);
    }

    protected static function canEditLineUnitCost(?AcquisitionPaperwork $record): bool
    {
        return $record !== null
            && $record->isPrApproved()
            && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT
            && ! self::isReceived($record);
    }

    protected static function isPrEditableFromGet(Get $get): bool
    {
        if (filled($get('../../received_at'))) {
            return false;
        }

        $prStatus = $get('../../pr_status');

        return $prStatus === null || $prStatus === AcquisitionPaperwork::STATUS_DRAFT;
    }

    protected static function isPrApprovedFromGet(Get $get): bool
    {
        return $get('../../pr_status') === AcquisitionPaperwork::STATUS_APPROVED;
    }

    protected static function canEditLineUnitCostFromGet(Get $get): bool
    {
        if (filled($get('../../received_at'))) {
            return false;
        }

        return self::isPrApprovedFromGet($get)
            && ($get('../../po_status') ?? AcquisitionPaperwork::STATUS_DRAFT) === AcquisitionPaperwork::STATUS_DRAFT;
    }

    protected static function showsLineCostFields(?AcquisitionPaperwork $record): bool
    {
        return false;
    }

    protected static function showsLineCostFieldsFromGet(Get $get): bool
    {
        return false;
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function prHeaderFields(): array
    {
        return [
            Placeholder::make('pr_number_preview')
                ->label('PR No.')
                ->content(fn (?AcquisitionPaperwork $record): string => (string) ($record?->pr_number ?: '—'))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Assigned automatically when offline approval is recorded.')
                ->visible(fn (?AcquisitionPaperwork $record): bool => filled($record?->pr_number))
                ->columnSpanFull(),
            Placeholder::make('pr_date_display')
                ->label('PR date')
                ->content(function (?AcquisitionPaperwork $record): string {
                    $date = $record?->pr_date ?? now();

                    return $date->format('M j, Y');
                })
                ->columnSpanFull(),
            Hidden::make('pr_date')
                ->default(fn (): string => now()->toDateString())
                ->dehydrated(),
            Placeholder::make('linked_requisitions_summary')
                ->label('Linked requisitions')
                ->content(function (Get $get): HtmlString {
                    $ids = collect($get('requisitions') ?? [])
                        ->map(fn (mixed $id): int => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if ($ids === []) {
                        return new HtmlString(
                            '<span class="text-sm text-gray-500">None selected. Open See Requisitions to link zero-stock requests.</span>'
                        );
                    }

                    $labels = Requisition::query()
                        ->whereKey($ids)
                        ->orderBy('reference_code')
                        ->pluck('reference_code')
                        ->filter()
                        ->map(fn (mixed $code): string => e((string) $code))
                        ->all();

                    return new HtmlString(
                        '<div class="owwa-linked-req-summary">'
                        .implode('', array_map(
                            static fn (string $label): string => '<span class="owwa-linked-req-chip">'.$label.'</span>',
                            $labels,
                        ))
                        .'</div>'
                    );
                })
                ->visible(fn (): bool => Filament::auth()->user()?->isSupplyCustodian() ?? false)
                ->columnSpanFull(),
            SchemaActions::make([
                Action::make('seeRequisitions')
                    ->label('See Requisitions')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->color('gray')
                    ->modalHeading('Zero-stock requisitions')
                    ->modalDescription('Select unit-consolidator requisitions with zero regional stock. Linking fills the PR items and requested quantities.')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitActionLabel('Link selected')
                    ->fillForm(fn (Get $get): array => [
                        'selected_requisitions' => collect($get('requisitions') ?? [])
                            ->map(fn (mixed $id): int => (int) $id)
                            ->filter()
                            ->values()
                            ->all(),
                    ])
                    ->schema(function (Get $get, ?AcquisitionPaperwork $record): array {
                        $rows = app(RequisitionPurchaseRequestService::class)
                            ->zeroStockRequisitionPickerRows(
                                (int) $get('item_category_id'),
                                collect($get('requisitions') ?? [])->all(),
                            );

                        return [
                            ViewField::make('selected_requisitions')
                                ->hiddenLabel()
                                ->default([])
                                ->view('filament.resources.acquisitions.paperwork.partials.zero-stock-requisitions-picker')
                                ->viewData([
                                    'rows' => $rows,
                                ]),
                        ];
                    })
                    ->action(function (array $data, Set $schemaSet, Get $schemaGet, ?AcquisitionPaperwork $record): void {
                        $ids = collect($data['selected_requisitions'] ?? [])
                            ->map(fn (mixed $id): int => (int) $id)
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        $schemaSet('requisitions', $ids);

                        if ($ids === []) {
                            $schemaSet('lines', [[
                                'item_id' => null,
                                'description' => null,
                                'unit' => null,
                                'quantity' => 1,
                            ]]);

                            return;
                        }

                        $schemaSet(
                            'lines',
                            app(RequisitionPurchaseRequestService::class)->buildLinkedLinePayload(
                                $ids,
                                (int) $schemaGet('item_category_id'),
                                $record?->id,
                            ),
                        );
                    })
                    ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
            ])
                ->visible(fn (): bool => Filament::auth()->user()?->isSupplyCustodian() ?? false)
                ->columnSpanFull(),
            Select::make('requisitions')
                ->label('Linked requisitions')
                ->relationship(
                    name: 'requisitions',
                    titleAttribute: 'reference_code',
                    modifyQueryUsing: function ($query, ?AcquisitionPaperwork $record) {
                        $existingIds = $record?->requisitions()->pluck('requisitions.id')->all() ?? [];

                        return $query->where(function ($eligibleQuery) use ($existingIds): void {
                            $eligibleQuery
                                ->where(function ($candidateQuery): void {
                                    $candidateQuery
                                        ->whereIn('status', [Requisition::STATUS_PENDING, Requisition::STATUS_ACCEPTED])
                                        ->whereHas('requestedBy', fn ($userQuery) => $userQuery->where('role', \App\Models\User::ROLE_UNIT_CONSOLIDATOR));
                                })
                                ->when($existingIds !== [], fn ($candidateQuery) => $candidateQuery->orWhereIn('id', $existingIds));
                        });
                    },
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->getOptionLabelFromRecordUsing(function (Requisition $record): string {
                    return (string) ($record->reference_code ?: "REQ #{$record->id}");
                })
                ->getSearchResultsUsing(fn (string $search): array => app(RequisitionPurchaseRequestService::class)->eligibleRequisitionOptions($search))
                ->live()
                ->afterStateUpdated(function (?array $state, ?array $old, Set $set, Get $get, ?AcquisitionPaperwork $record): void {
                    $ids = collect($state ?? [])
                        ->map(fn (mixed $id): int => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if ($ids === []) {
                        if (($old ?? []) !== []) {
                            $set('lines', [[
                                'item_id' => null,
                                'description' => null,
                                'unit' => null,
                                'quantity' => 1,
                            ]]);
                        }

                        return;
                    }

                    $set('lines', app(RequisitionPurchaseRequestService::class)->buildLinkedLinePayload(
                        $ids,
                        (int) $get('item_category_id'),
                        $record?->id,
                    ));
                })
                ->hidden()
                ->dehydratedWhenHidden()
                ->saveRelationshipsWhenHidden()
                ->dehydrated(fn (): bool => Filament::auth()->user()?->isSupplyCustodian() ?? false)
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record))
                ->columnSpanFull(),
            Textarea::make('purpose')
                ->label('Purpose')
                ->required()
                ->minLength(8)
                ->rows(3)
                ->helperText('Enter at least 8 characters.')
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record))
                ->columnSpanFull(),
            TextInput::make('requested_by_name')
                ->label('Requested by (printed name)')
                ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(\App\Models\ProcurementSignatoryName::ROLE_REQUESTED))
                ->maxLength(255)
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
            TextInput::make('approved_by_name')
                ->label('Approved by (printed name)')
                ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(\App\Models\ProcurementSignatoryName::ROLE_APPROVED))
                ->maxLength(255)
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
        ];
    }

    protected static function prLineItemsRepeater(callable $scopeActive): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->hiddenLabel()
            ->extraAttributes([
                'class' => 'owwa-acquisition-lines-repeater fi-fixed-positioning-context',
            ])
            ->hintIcon(Heroicon::QuestionMarkCircle, 'Pick the catalog item by name. Stock No. / Inventory item no. / Property No. fills from the Items register and is used on PR/PO/IAR Column A.')
            ->addable(fn (?AcquisitionPaperwork $record): bool => self::isPrEditable($record))
            ->deletable(fn (?AcquisitionPaperwork $record): bool => self::isPrEditable($record))
            ->table(function (Get $get, ?AcquisitionPaperwork $record): array {
                $showsCosts = self::showsLineCostFields($record)
                    || self::showsLineCostFieldsFromGet($get);

                $columns = [
                    TableColumn::make('Item')
                        ->markAsRequired()
                        ->width($showsCosts ? '18%' : '30%'),
                    TableColumn::make(self::lineIdentifierColumnLabel((int) $get('item_category_id')))
                        ->wrapHeader()
                        ->width($showsCosts ? '14%' : '17%'),
                    TableColumn::make('Description')->width($showsCosts ? '16%' : '31%'),
                    TableColumn::make('Unit')->width($showsCosts ? '9%' : '9%'),
                    TableColumn::make(filled($get('requisitions')) ? 'Requested qty' : 'Qty')
                        ->markAsRequired()
                        ->width($showsCosts ? '7%' : '10%'),
                ];

                if ($showsCosts) {
                    $columns[] = TableColumn::make('Unit cost')->markAsRequired()->width('14%');
                    $columns[] = TableColumn::make('Total')->width('12%');
                }

                return $columns;
            })
            ->compact()
            ->schema([
                Select::make('item_id')
                    ->label('Item')
                    ->hiddenLabel()
                    ->searchable()
                    ->options(function (Get $get) use ($scopeActive): array {
                        $query = Item::query();
                        $scopeActive($query);
                        $categoryId = $get('../../item_category_id');

                        if (filled($categoryId)) {
                            $query->where('item_category_id', $categoryId);
                        }

                        return $query->orderBy('name')->limit(100)->pluck('name', 'id')->all();
                    })
                    ->getSearchResultsUsing(function (string $search, Get $get) use ($scopeActive): array {
                        $query = Item::query()->with(['category', 'uacsObjectCode']);
                        $scopeActive($query);
                        $categoryId = $get('../../item_category_id');

                        if (filled($categoryId)) {
                            $query->where('item_category_id', $categoryId);
                        }

                        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                        return $query
                            ->where(function ($q) use ($term): void {
                                $q->where('name', 'like', $term)
                                    ->orWhere('item_code', 'like', $term)
                                    ->orWhere('semi_expendable_property_number', 'like', $term)
                                    ->orWhere('ppe_property_number', 'like', $term);
                            })
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Item::query()->whereKey($value)->value('name'))
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)
                        || filled($get('../../requisitions')))
                    ->dehydrated()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if (blank($state)) {
                            $set('description', null);
                            $set('unit', null);

                            return;
                        }

                        $item = Item::query()->find($state);
                        if ($item === null) {
                            return;
                        }

                        $set('description', $item->name);
                        $set('unit', $item->unit);
                    }),
                Placeholder::make('catalog_identifier_preview')
                    ->hiddenLabel()
                    ->content(function (Get $get): HtmlString {
                        $itemId = $get('item_id');
                        if (blank($itemId)) {
                            return new HtmlString('<span class="owwa-cell-muted">—</span>');
                        }

                        $item = Item::query()->with(['category', 'uacsObjectCode'])->find($itemId);
                        if ($item === null) {
                            return new HtmlString('<span class="owwa-cell-muted">—</span>');
                        }

                        $identifier = app(\App\Services\CatalogAssetNumberService::class)
                            ->catalogIdentifierForItem($item);

                        if (blank($identifier)) {
                            return new HtmlString('<span class="owwa-cell-muted">—</span>');
                        }

                        return new HtmlString(
                            '<span style="display:block;word-break:break-all;font-size:0.8125rem;line-height:1.25;">'
                            .e((string) $identifier)
                            .'</span>'
                        );
                    }),
                Textarea::make('description')
                    ->label('Description')
                    ->hiddenLabel()
                    ->rows(1)
                    ->autosize()
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-desc'])
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)),
                TextInput::make('unit')
                    ->label('Unit')
                    ->hiddenLabel()
                    ->disabled()
                    ->dehydrated()
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-unit']),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true)
                    ->extraInputAttributes([
                        'class' => 'owwa-acquisition-line-qty',
                        'inputmode' => 'numeric',
                    ])
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)
                        || filled($get('../../requisitions')))
                    ->dehydrated(),
                TextInput::make('unit_cost')
                    ->label('Unit cost')
                    ->hiddenLabel()
                    ->numeric()
                    ->prefix('₱')
                    ->required()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::showsLineCostFieldsFromGet($get))
                    ->extraInputAttributes([
                        'class' => 'owwa-acquisition-line-unit-cost',
                        'inputmode' => 'decimal',
                    ])
                    ->disabled(fn (Get $get): bool => ! self::canEditLineUnitCostFromGet($get)),
                Placeholder::make('line_total_preview')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => self::showsLineCostFieldsFromGet($get))
                    ->extraAttributes(['class' => 'owwa-acquisition-line-total'])
                    ->content(function (Get $get): string {
                        $quantity = (int) ($get('quantity') ?? 0);
                        $unitCost = $get('unit_cost');

                        if ($quantity <= 0 || blank($unitCost)) {
                            return '—';
                        }

                        return '₱'.number_format((float) $unitCost * $quantity, 2);
                    }),
            ])
            ->defaultItems(1)
            ->minItems(1)
            ->addActionLabel('Add line');
    }

    protected static function lineIdentifierColumnLabel(?int $categoryId): string
    {
        if ($categoryId === null || $categoryId <= 0) {
            return 'Stock No. / Property No.';
        }

        $slug = ItemCategory::query()->find($categoryId)?->getTemplateSlug();

        return app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierLabel($slug);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function poFields(): array
    {
        return [
            Placeholder::make('po_number_preview')
                ->label('PO No.')
                ->content(fn (?AcquisitionPaperwork $record): string => filled($record?->po_number)
                    ? (string) $record->po_number
                    : 'Next: '.app(ReferenceCodeService::class)->previewNext(ReferenceSeries::typeForAcquisitionPaperworkPo()))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Assigned automatically when you complete the PO phase.'),
            TextInput::make('supplier')
                ->label('Supplier')
                ->required(fn (?AcquisitionPaperwork $record): bool => $record?->isPrApproved() ?? false),
            DatePicker::make('po_date')
                ->label('PO date')
                ->default(now())
                ->required(fn (?AcquisitionPaperwork $record): bool => $record?->isPrApproved() ?? false),
            TextInput::make('po_data.address')
                ->label('Supplier address'),
            TextInput::make('po_data.tin')
                ->label('TIN'),
            TextInput::make('po_data.mode_of_procurement')
                ->label('Mode of procurement'),
            TextInput::make('po_data.place_of_delivery')
                ->label('Place of delivery'),
            TextInput::make('po_data.delivery_term')
                ->label('Delivery term'),
            DatePicker::make('po_data.date_of_delivery')
                ->label('Date of delivery'),
            TextInput::make('po_data.payment_term')
                ->label('Payment term'),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function iarFields(): array
    {
        return [
            Placeholder::make('iar_number_preview')
                ->label('IAR No.')
                ->content(fn (?AcquisitionPaperwork $record): string => filled($record?->iar_number)
                    ? (string) $record->iar_number
                    : 'Next: '.app(ReferenceCodeService::class)->previewNext(ReferenceSeries::typeForAcquisitionPaperworkIar()))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Assigned automatically when you complete the IAR phase.'),
            DatePicker::make('iar_date')
                ->label('IAR date')
                ->default(now())
                ->required(fn (?AcquisitionPaperwork $record): bool => $record?->isPoApproved() ?? false),
            TextInput::make('iar_data.invoice_no')
                ->label('Invoice No.'),
            DatePicker::make('iar_data.invoice_date')
                ->label('Invoice date'),
            DatePicker::make('iar_data.date_inspected')
                ->label('Date inspected'),
            DatePicker::make('iar_data.date_received')
                ->label('Date received'),
            TextInput::make('inspection_officer_name')
                ->label('Inspection officer'),
            TextInput::make('custodian_name')
                ->label('Supply / property custodian'),
        ];
    }

    protected static function isSemiExpendableCategoryId(?int $categoryId): bool
    {
        if ($categoryId === null || $categoryId <= 0) {
            return false;
        }

        return ItemCategory::query()->find($categoryId)?->getTemplateSlug() === 'semi_expendable';
    }
}
