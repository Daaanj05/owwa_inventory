<?php

namespace App\Filament\Resources\Transfers\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\ProcurementSignatoryName;
use App\Models\Transfer;
use App\Services\InventoryStockService;
use App\Services\TransferItemOptionsService;
use App\Support\CustodianOfficeScope;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionNotificationRecipients;
use App\Support\SignatorySelect;
use App\Support\SupplyOfficeResolver;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Placeholder::make('transfer_workflow_hint')
                    ->hiddenLabel()
                    ->content('Transfers move stock between offices. To issue items to a department or employee within your office, use Issuance.')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'owwa-transfer-workflow-hint']),

                Section::make('Step 1 — Offices')
                    ->description('Choose where stock is leaving and where it is going.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('from_office_id')
                            ->label('From office')
                            ->relationship(
                                'fromOffice',
                                'name',
                                $scopeActive,
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                            ->helperText('Select where stock is leaving. You manage all offices; stock is checked at the office you choose.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $set('item_id', null);
                                $set('return_issuance_id', null);
                                $set('property_number', null);
                                $set('inventory_unit_id', null);
                                $set('from_accountable_officer', self::defaultAccountableOfficerName(
                                    filled($state) ? (int) $state : null,
                                ));
                                if ($get('transfer_type') === Transfer::TYPE_RETURN) {
                                    self::lockReturnDestination($set);

                                    return;
                                }
                                if (filled($state) && (int) $get('to_office_id') === (int) $state) {
                                    $set('to_office_id', null);
                                    $set('to_accountable_officer', null);
                                }
                            }),
                        Select::make('to_office_id')
                            ->label('To office')
                            ->options(function (Get $get): array {
                                if ($get('transfer_type') === Transfer::TYPE_RETURN) {
                                    return self::regionalOfficeOption();
                                }

                                $fromOfficeId = $get('from_office_id');
                                if (blank($fromOfficeId)) {
                                    return [];
                                }

                                return Office::query()
                                    ->active()
                                    ->whereKeyNot((int) $fromOfficeId)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('from_office_id')) || $get('transfer_type') === Transfer::TYPE_RETURN)
                            ->dehydrated()
                            ->rules(fn (Get $get): array => $get('transfer_type') === Transfer::TYPE_RETURN
                                ? []
                                : ['different:from_office_id'])
                            ->validationMessages([
                                'different' => 'Destination office must be different from the source office.',
                            ])
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                if ($get('transfer_type') === Transfer::TYPE_RETURN) {
                                    return;
                                }

                                $set('item_id', null);
                                $set('property_number', null);
                                $set('to_accountable_officer', self::defaultAccountableOfficerName(
                                    filled($state) ? (int) $state : null,
                                ));
                            }),
                        Placeholder::make('return_to_stock_hint')
                            ->hiddenLabel()
                            ->content('This quantity returns to regional stock. It is not taken from another office’s on-hand.')
                            ->visible(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_RETURN)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'owwa-transfer-return-hint']),
                    ])
                    ->columns(2),

                Section::make('Step 2 — Item & quantity')
                    ->description('Return to stock lists issued property. Other types list on-hand at the From office.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::transfer())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => InventoryCategoryOptions::allActiveCategoryOptions())
                            ->placeholder('All categories')
                            ->default(fn (): ?int => self::activeCategoryFilter())
                            ->disabled(fn (): bool => self::isCategoryScoped())
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('item_id', null);
                                $set('return_issuance_id', null);
                                $set('property_number', null);
                                $set('inventory_unit_id', null);
                            }),
                        Select::make('return_issuance_id')
                            ->label('Issued item')
                            ->options(function (Get $get): array {
                                $fromOfficeId = $get('from_office_id');
                                if (blank($fromOfficeId) || $get('transfer_type') !== Transfer::TYPE_RETURN) {
                                    return [];
                                }

                                $categoryId = $get('item_category_filter');

                                return app(TransferItemOptionsService::class)->issuedOptionsForOffice(
                                    (int) $fromOfficeId,
                                    filled($categoryId) ? (int) $categoryId : null,
                                );
                            })
                            ->required(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_RETURN)
                            ->visible(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_RETURN)
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->placeholder('Select the issued property')
                            ->helperText('Choose the employee and property number being returned.')
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (blank($state)) {
                                    $set('item_id', null);
                                    $set('property_number', null);
                                    $set('inventory_unit_id', null);

                                    return;
                                }

                                $issuance = Issuance::query()->with('inventoryUnit')->find($state);
                                if ($issuance === null) {
                                    return;
                                }

                                $set('item_id', $issuance->item_id);
                                $set('property_number', $issuance->property_number);
                                $set('unit_cost', $issuance->unit_cost);
                                $set('inventory_unit_id', $issuance->inventoryUnit?->id);
                            }),
                        Hidden::make('inventory_unit_id'),
                        Select::make('item_id')
                            ->label('Item')
                            ->options(function (Get $get): array {
                                if ($get('transfer_type') === Transfer::TYPE_RETURN) {
                                    $itemId = $get('item_id');
                                    if (blank($itemId)) {
                                        return [];
                                    }

                                    $name = Item::query()->whereKey($itemId)->value('name');

                                    return [(int) $itemId => $name ?: 'Issued item'];
                                }

                                $fromOfficeId = $get('from_office_id');
                                if (blank($fromOfficeId) || blank($get('to_office_id'))) {
                                    return [];
                                }

                                $categoryId = $get('item_category_filter');

                                return app(TransferItemOptionsService::class)->optionsForFromOffice(
                                    (int) $fromOfficeId,
                                    filled($categoryId) ? (int) $categoryId : null,
                                );
                            })
                            ->hidden(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_RETURN)
                            ->dehydrated()
                            ->required()
                            ->searchable()
                            ->live()
                            ->disabled(fn (Get $get): bool => blank($get('from_office_id')) || blank($get('to_office_id')))
                            ->placeholder(fn (Get $get): string => blank($get('from_office_id')) || blank($get('to_office_id'))
                                ? 'Select offices first'
                                : 'Select an item')
                            ->helperText(function (Get $get): ?string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return null;
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                return $stock === 0
                                    ? 'No stock available — increase stock before transferring.'
                                    : null;
                            })
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                if ($get('transfer_type') === Transfer::TYPE_RETURN) {
                                    return;
                                }

                                $set('unit_cost', null);
                                $set('inventory_unit_id', null);
                                $set('property_number', self::catalogPropertyNumberForItem(
                                    filled($state) ? (int) $state : null,
                                ));
                            }),
                        Select::make('unit_cost')
                            ->label('Unit cost bucket')
                            ->options(function (Get $get): array {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return [];
                                }

                                $buckets = app(InventoryStockService::class)->getUnitCostBucketsWithStock(
                                    (int) $itemId,
                                    (int) $fromOfficeId,
                                );

                                $options = [];
                                foreach ($buckets as $cost => $qty) {
                                    $options[(string) $cost] = '₱'.number_format((float) $cost, 2)." ({$qty} on hand)";
                                }

                                return $options;
                            })
                            ->required(fn (Get $get): bool => $get('transfer_type') !== Transfer::TYPE_RETURN
                                && count(app(InventoryStockService::class)->getUnitCostBucketsWithStock(
                                    (int) ($get('item_id') ?? 0),
                                    (int) ($get('from_office_id') ?? 0),
                                )) > 1)
                            ->visible(fn (Get $get): bool => $get('transfer_type') !== Transfer::TYPE_RETURN
                                && filled($get('item_id'))
                                && filled($get('from_office_id'))
                                && count(app(InventoryStockService::class)->getUnitCostBucketsWithStock(
                                    (int) ($get('item_id') ?? 0),
                                    (int) ($get('from_office_id') ?? 0),
                                )) > 1)
                            ->native(false)
                            ->live()
                            ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (float) $state : null),
                        Placeholder::make('available_stock_preview')
                            ->label('Available at source office')
                            ->content(function (Get $get): string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return '—';
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                return (string) $stock;
                            })
                            ->visible(fn (Get $get): bool => $get('transfer_type') !== Transfer::TYPE_RETURN
                                && filled($get('item_id'))
                                && filled($get('from_office_id'))),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(function (Get $get, $livewire): ?int {
                                $maximum = self::quantityMaximum($get, $livewire);

                                return $maximum > 0 ? $maximum : null;
                            })
                            ->helperText(function (Get $get, $livewire): ?string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return null;
                                }

                                $maximum = self::quantityMaximum($get, $livewire);

                                return $get('transfer_type') === Transfer::TYPE_RETURN
                                    ? "Maximum still issued: {$maximum}"
                                    : "Maximum: {$maximum}";
                            }),
                        DatePicker::make('transfer_date')
                            ->label('Transfer date')
                            ->required()
                            ->default(now()),
                        Select::make('condition')
                            ->label('Condition of property')
                            ->options([
                                'Serviceable' => 'Serviceable',
                                'Unserviceable' => 'Unserviceable',
                                'Good' => 'Good',
                                'Poor' => 'Poor',
                            ])
                            ->placeholder('Select condition'),
                        Select::make('transfer_type')
                            ->label('Transfer type')
                            ->options(Transfer::typeOptions())
                            ->placeholder('Select type')
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('item_id', null);
                                $set('return_issuance_id', null);
                                $set('property_number', null);
                                $set('inventory_unit_id', null);

                                if ($state === Transfer::TYPE_RETURN) {
                                    self::lockReturnDestination($set);

                                    return;
                                }

                                if ($state !== Transfer::TYPE_OTHERS) {
                                    $set('transfer_type_other', null);
                                }
                            }),
                        TextInput::make('transfer_type_other')
                            ->label('Others (specify)')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_OTHERS)
                            ->visible(fn (Get $get): bool => $get('transfer_type') === Transfer::TYPE_OTHERS),
                    ])
                    ->columns(2),

                Section::make('Accountable officers')
                    ->description('The person’s name only, for PTR rows 8–9. Not an agency and not a fund cluster.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('from_accountable_officer')
                            ->label('From accountable officer')
                            ->maxLength(255)
                            ->helperText('Accountable officer’s name only.'),
                        TextInput::make('to_accountable_officer')
                            ->label('To accountable officer')
                            ->maxLength(255)
                            ->helperText('Accountable officer’s name only.'),
                        Textarea::make('reason_for_transfer')
                            ->label('Reason for transfer')
                            ->rows(2)
                            ->helperText('PTR cell A44')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => self::usesPtrForm($get('item_category_filter'))),

                Section::make('Additional details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('stock_number_display')
                            ->label(OwwaReferenceLabels::STOCK_NO)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('item_id'))
                                && ! OwwaReferenceLabels::usesPropertyNumber(
                                    OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                                ))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return;
                                }

                                $code = Item::query()->whereKey($itemId)->value('item_code');
                                $component->state(filled($code) ? $code : '—');
                            })
                            ->helperText(OwwaReferenceLabels::stockNumberHelperText()),
                        TextInput::make('property_number')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::assetIdentifierLabel(
                                OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                            ))
                            ->disabled()
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => filled($get('item_id'))
                                && OwwaReferenceLabels::usesPropertyNumber(
                                    OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                                ))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                if (filled($state)) {
                                    return;
                                }

                                $number = self::catalogPropertyNumberForItem(
                                    filled($get('item_id')) ? (int) $get('item_id') : null,
                                );
                                if (filled($number)) {
                                    $component->state($number);
                                }
                            })
                            ->helperText('Auto-filled from the selected catalog item.'),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Signatories')
                    ->columnSpanFull()
                    ->schema([
                        SignatorySelect::make('approved_by_printed_name', ProcurementSignatoryName::ROLE_TRANSFER_APPROVED)
                            ->label('Approve By')
                            ->helperText('Name of the Approver')
                            ->placeholder('Full name')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('approved_by_designation', ProcurementSignatoryName::designationFor(
                                    ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
                                    is_string($state) ? $state : null,
                                ));
                            }),
                        SignatorySelect::makeDesignation('approved_by_designation', ProcurementSignatoryName::ROLE_TRANSFER_APPROVED)
                            ->label('Designation')
                            ->helperText('Designation of the selected person'),
                        SignatorySelect::make('released_by_printed_name', ProcurementSignatoryName::ROLE_TRANSFER_RELEASED)
                            ->label('Released By')
                            ->helperText('Name of the Issuer')
                            ->placeholder('Full name')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('released_by_designation', ProcurementSignatoryName::designationFor(
                                    ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
                                    is_string($state) ? $state : null,
                                ));
                            }),
                        SignatorySelect::makeDesignation('released_by_designation', ProcurementSignatoryName::ROLE_TRANSFER_RELEASED)
                            ->label('Designation')
                            ->helperText('Designation of the selected person'),
                        SignatorySelect::make('received_by_printed_name', ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED)
                            ->label('Received By')
                            ->helperText('Name of the Receiver')
                            ->placeholder('Full name')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('received_by_designation', ProcurementSignatoryName::designationFor(
                                    ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED,
                                    is_string($state) ? $state : null,
                                ));
                            }),
                        SignatorySelect::makeDesignation('received_by_designation', ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED)
                            ->label('Designation')
                            ->helperText('Designation of the selected person'),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => self::usesPtrForm($get('item_category_filter'))),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function accountableOfficerSuggestions(?int $officeId, string $rememberRole): array
    {
        $names = collect(ProcurementSignatoryName::suggestionsForRole($rememberRole));

        if ($officeId !== null) {
            $ucs = RequisitionNotificationRecipients::unitConsolidatorsForOffice($officeId);
            $names = $names->merge($ucs->pluck('name'));

            if ($ucs->isEmpty()) {
                $officeName = Office::query()->whereKey($officeId)->value('accountable_officer_name');
                if (filled($officeName)) {
                    $names->push((string) $officeName);
                }
            }
        }

        return $names
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public static function defaultAccountableOfficerName(?int $officeId): ?string
    {
        if ($officeId === null) {
            return null;
        }

        $ucs = RequisitionNotificationRecipients::unitConsolidatorsForOffice($officeId);
        if ($ucs->count() === 1) {
            return $ucs->first()?->name;
        }

        if ($ucs->isNotEmpty()) {
            return null;
        }

        $name = Office::query()->whereKey($officeId)->value('accountable_officer_name');

        return filled($name) ? (string) $name : null;
    }

    /**
     * @return array<int, string>
     */
    public static function regionalOfficeOption(): array
    {
        $regionalOfficeId = app(SupplyOfficeResolver::class)->resolve();
        if ($regionalOfficeId === null) {
            return [];
        }

        $name = Office::query()->whereKey($regionalOfficeId)->value('name');

        return [$regionalOfficeId => $name ?: 'Regional office'];
    }

    public static function lockReturnDestination(Set $set): void
    {
        $regionalOfficeId = app(SupplyOfficeResolver::class)->resolve();
        $set('to_office_id', $regionalOfficeId);
        $set('to_accountable_officer', self::defaultAccountableOfficerName($regionalOfficeId));
    }

    public static function quantityMaximum(Get $get, mixed $livewire): int
    {
        $itemId = $get('item_id');
        $fromOfficeId = $get('from_office_id');
        if (blank($itemId) || blank($fromOfficeId)) {
            return 0;
        }

        $options = app(TransferItemOptionsService::class);
        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
        $existing = $record instanceof Transfer ? $record : null;

        if ($get('transfer_type') === Transfer::TYPE_RETURN) {
            $propertyNumber = filled($get('property_number')) ? (string) $get('property_number') : null;

            return $options->stillIssuedQuantity((int) $itemId, (int) $fromOfficeId, $propertyNumber, $existing);
        }

        $stock = $options->availableStock((int) $itemId, (int) $fromOfficeId);

        if ($existing instanceof Transfer
            && (int) $existing->item_id === (int) $itemId
            && (int) $existing->from_office_id === (int) $fromOfficeId
            && $existing->transfer_type !== Transfer::TYPE_RETURN) {
            $stock += (int) $existing->quantity;
        }

        return $stock;
    }

    public static function catalogPropertyNumberForItem(?int $itemId): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $item = Item::query()->with('category')->find($itemId);
        if ($item === null) {
            return null;
        }

        $slug = $item->category?->getTemplateSlug();

        $identifier = match ($slug) {
            'semi_expendable' => $item->resolvedSemiExpendablePropertyNumber(),
            'ppe' => $item->resolvedPpePropertyNumber(),
            default => null,
        };

        return filled($identifier) ? (string) $identifier : null;
    }

    protected static function usesPtrForm(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::find($categoryId);
        $slug = $category?->getTemplateSlug();

        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (filled(request()->query('category')) || filled(session('active_item_category_id')));
    }

    protected static function activeCategoryFilter(): ?int
    {
        if (! self::isCategoryScoped()) {
            return null;
        }

        return SyncsActiveItemCategory::resolveCategoryIdFromContext();
    }
}
