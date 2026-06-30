<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Schemas;

use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ReferenceSeries;
use App\Services\ReferenceCodeService;
use App\Support\AcquisitionPaperworkViewPresenter;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaReferenceLabels;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;

class AcquisitionPaperworkForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                SchemaView::make('filament.resources.acquisitions.paperwork.partials.view-acquisition-paperwork-hero')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->viewData(fn (?AcquisitionPaperwork $record): array => $record
                        ? AcquisitionPaperworkViewPresenter::forPaperwork($record)
                        : []),
                SchemaView::make('filament.resources.acquisitions.partials.acquisition-workflow-stepper')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->viewData(fn (?AcquisitionPaperwork $record): array => [
                        'workflowSteps' => AcquisitionPaperworkViewPresenter::workflowStepsForForm($record),
                        'clickable' => false,
                        'compact' => true,
                    ]),
                Section::make('Acquisition paperwork')
                    ->description('Fill details, complete each phase, then export and print the OWWA form for offline approval.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::acquisitionPaperwork())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation !== 'create'),
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                fn ($query) => CustodianOfficeScope::officeQuery($query),
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                            ->disabled(fn (?AcquisitionPaperwork $record): bool => CustodianOfficeScope::hasFixedInventoryOffice() || ! self::isPrEditable($record))
                            ->dehydrated(),
                        Select::make('item_category_id')
                            ->label('Item category')
                            ->options(fn (): array => ItemCategory::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): mixed => session('active_item_category_id'))
                            ->required()
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        Select::make('department_id')
                            ->label('Department / section')
                            ->relationship('department', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
                    ]),
                Section::make('Purchase request (Appendix 60)')
                    ->description(fn (?AcquisitionPaperwork $record): ?string => self::isPrEditable($record)
                        ? 'Fill PR header and line items, then submit for approval.'
                        : 'PR submitted — awaiting approval.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => $operation === 'create' || ! ($record?->isPrApproved() ?? false))
                    ->columns(2)
                    ->schema(self::prHeaderFields()),
                Section::make('Line items')
                    ->description(fn (?AcquisitionPaperwork $record): string => match (true) {
                        $record?->isPrApproved() && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT => 'Confirm unit costs on each line before submitting PO.',
                        self::isPrEditable($record) => 'Add items, quantities, and unit costs for this purchase request.',
                        default => 'Line items for this acquisition.',
                    })
                    ->columns(2)
                    ->schema([
                        self::prLineItemsRepeater($scopeActive)->columnSpanFull(),
                    ]),
                Section::make('Purchase order (Appendix 61)')
                    ->description('Fill supplier and delivery details, then Submit PO for approval.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => $operation !== 'create'
                        && ($record?->isPrApproved() ?? false)
                        && ! ($record?->isPoApproved() ?? false))
                    ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPoEditable($record))
                    ->columns(2)
                    ->schema(self::poFields()),
                Section::make('Inspection & acceptance (Appendix 62)')
                    ->description('Record inspection signatories, then Submit IAR for approval.')
                    ->visible(fn (string $operation, ?AcquisitionPaperwork $record): bool => $operation !== 'create'
                        && ($record?->isPoApproved() ?? false)
                        && ! ($record?->isIarApproved() ?? false))
                    ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isIarEditable($record))
                    ->schema(self::iarFields()),
            ]);
    }

    protected static function isReceived(?AcquisitionPaperwork $record): bool
    {
        return $record?->isReceived() ?? false;
    }

    protected static function isPrEditable(?AcquisitionPaperwork $record): bool
    {
        return $record !== null
            && $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT
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
        return self::isPrEditable($record) || (
            $record !== null
            && $record->isPrApproved()
            && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT
            && ! self::isReceived($record)
        );
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

        if (self::isPrEditableFromGet($get)) {
            return true;
        }

        return self::isPrApprovedFromGet($get)
            && ($get('../../po_status') ?? AcquisitionPaperwork::STATUS_DRAFT) === AcquisitionPaperwork::STATUS_DRAFT;
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function prHeaderFields(): array
    {
        return [
            Placeholder::make('pr_number_preview')
                ->label('PR No.')
                ->content(fn (?AcquisitionPaperwork $record): string => filled($record?->pr_number)
                    ? (string) $record->pr_number
                    : 'Next: '.app(ReferenceCodeService::class)->previewNext(ReferenceSeries::typeForAcquisitionPaperworkPr()))
                ->helperText('Assigned automatically when you complete the PR phase.')
                ->visible(fn (string $operation): bool => $operation !== 'create')
                ->columnSpanFull(),
            DatePicker::make('pr_date')
                ->label('PR date')
                ->default(now())
                ->required()
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record))
                ->columnSpanFull(),
            Textarea::make('purpose')
                ->label('Purpose')
                ->required()
                ->rows(3)
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record))
                ->columnSpanFull(),
            TextInput::make('requested_by_name')
                ->label('Requested by (printed name)')
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
            TextInput::make('approved_by_name')
                ->label('Approved by (printed name)')
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record)),
            Textarea::make('remarks')
                ->label('Remarks')
                ->rows(2)
                ->disabled(fn (?AcquisitionPaperwork $record): bool => ! self::isPrEditable($record))
                ->columnSpanFull(),
        ];
    }

    protected static function prLineItemsRepeater(callable $scopeActive): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->label('')
            ->helperText('Stock No. comes from the item catalog. Register the item under Items first if it is not in the list.')
            ->addable(fn (?AcquisitionPaperwork $record): bool => self::isPrEditable($record))
            ->deletable(fn (?AcquisitionPaperwork $record): bool => self::isPrEditable($record))
            ->schema([
                Select::make('item_id')
                    ->label('Item (Stock No.)')
                    ->options(function (Get $get) use ($scopeActive): array {
                        $query = Item::query();
                        $scopeActive($query);
                        $categoryId = $get('../../item_category_id');

                        if (filled($categoryId)) {
                            $query->where('item_category_id', $categoryId);
                        }

                        return $query->orderBy('item_code')->get()
                            ->mapWithKeys(fn (Item $item): array => [
                                $item->id => trim($item->item_code.' — '.$item->name),
                            ])
                            ->all();
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get))
                    ->columnSpanFull()
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
                Placeholder::make('stock_no_display')
                    ->label('Stock No.')
                    ->content(function (Get $get): string {
                        $itemId = $get('item_id');
                        if (blank($itemId)) {
                            return '—';
                        }

                        return (string) (Item::query()->find($itemId)?->item_code ?? '—');
                    })
                    ->visible(fn (Get $get): bool => filled($get('item_id'))),
                TextInput::make('description')
                    ->label('Description')
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)),
                TextInput::make('unit')
                    ->label('Unit')
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get)),
                TextInput::make('unit_cost')
                    ->label('Unit cost')
                    ->numeric()
                    ->prefix('₱')
                    ->required(fn (Get $get): bool => self::isPrEditableFromGet($get) || self::canEditLineUnitCostFromGet($get))
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get): bool => ! self::canEditLineUnitCostFromGet($get)),
                Placeholder::make('line_total_preview')
                    ->label('Line total')
                    ->content(function (Get $get): string {
                        $quantity = (int) ($get('quantity') ?? 0);
                        $unitCost = $get('unit_cost');

                        if ($quantity <= 0 || blank($unitCost)) {
                            return '—';
                        }

                        return '₱'.number_format((float) $unitCost * $quantity, 2);
                    }),
                TextInput::make('line_remarks')
                    ->label('Remarks')
                    ->disabled(fn (Get $get): bool => ! self::isPrEditableFromGet($get))
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->defaultItems(1)
            ->minItems(1)
            ->addActionLabel('Add line');
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
                ->helperText('Assigned automatically when you complete the PO phase.'),
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
                ->helperText('Assigned automatically when you complete the IAR phase.'),
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
}
