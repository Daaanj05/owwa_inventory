<?php

namespace App\Filament\Resources\Disposals\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\DisposalInventoryUnitService;
use App\Services\InventoryStockService;
use App\Support\CustodianOfficeScope;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\SupplyOfficeResolver;
use Closure;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class DisposalForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();
        $unitService = app(DisposalInventoryUnitService::class);

        $syncItemOffice = function (Get $get, Set $set) use ($unitService): void {
            $unitService->syncFormStateForItemOffice(
                self::intOrNull($get('item_id')),
                self::intOrNull($get('office_id')),
                $set,
            );
        };

        return $schema
            ->columns(1)
            ->components([
                Hidden::make('disposal_type')
                    ->default(fn (): ?string => self::defaultDisposalType())
                    ->dehydrated(),

                Hidden::make('inventory_auto_synced')
                    ->dehydrated(false)
                    ->default(false),

                Hidden::make('inventory_unit_id')
                    ->dehydrated(),

                Hidden::make('par_issuance_id')
                    ->default(null)
                    ->dehydrated(fn (): bool => self::activeCategorySlug() !== 'consumables')
                    ->dehydrateStateUsing(fn (mixed $state): ?int => self::activeCategorySlug() === 'consumables'
                        ? null
                        : (filled($state) ? (int) $state : null)),

                Section::make('Record disposal')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(fn (): string => OwwaReferenceLabels::disposal(self::activeCategorySlug()))
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),

                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => InventoryCategoryOptions::allActiveCategoryOptions())
                            ->placeholder('All categories')
                            ->default(fn (): ?int => self::activeCategoryFilter())
                            ->visible(fn (): bool => ! self::isCategoryScoped())
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set) use ($unitService): void {
                                $set('item_id', null);
                                $unitService->clearUnitLinkedFields($set);
                            })
                            ->columnSpanFull(),

                        Hidden::make('disposal_date')
                            ->default(fn (): string => now()->toDateString())
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (): string => now()->toDateString()),

                        Placeholder::make('disposal_date_display')
                            ->label('Date')
                            ->content(fn (): string => now()->format('M d, Y')),

                        ...self::officeFields($syncItemOffice),

                        Select::make('item_id')
                            ->label('Item')
                            ->relationship(
                                'item',
                                'name',
                                function (Builder $query, Get $get) use ($scopeActive) {
                                    $query = $scopeActive($query);
                                    $categoryId = $get('item_category_filter');
                                    if (filled($categoryId)) {
                                        $query->where('item_category_id', $categoryId);
                                    }

                                    return $query;
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated($syncItemOffice),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->dehydrated()
                            ->extraFieldWrapperAttributes(fn (Get $get): array => filled($get('item_id'))
                                ? []
                                : ['x-data' => '{ showSelectItemError: false }'])
                            ->extraInputAttributes(fn (Get $get): array => filled($get('item_id'))
                                ? []
                                : [
                                    'x-on:focus' => 'showSelectItemError = true; $event.target.blur()',
                                ])
                            ->belowContent(fn (Get $get): ?HtmlString => filled($get('item_id'))
                                ? null
                                : new HtmlString(
                                    '<p x-show="showSelectItemError" x-cloak class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">Please select an item first.</p>'
                                ))
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (self::activeCategorySlug() !== 'consumables') {
                                        return;
                                    }

                                    $itemId = self::intOrNull($get('item_id'));
                                    $officeId = self::intOrNull($get('office_id'))
                                        ?? app(SupplyOfficeResolver::class)->resolve();

                                    if ($itemId === null || $officeId === null) {
                                        return;
                                    }

                                    $available = app(InventoryStockService::class)->getStock($itemId, $officeId);
                                    $qty = (int) $value;

                                    if ($qty > $available) {
                                        $fail("Quantity exceeds regional warehouse stock on hand ({$available}).");
                                    }
                                },
                            ]),

                    ])
                    ->columns(2),

                Section::make('Asset details')
                    ->columnSpanFull()
                    ->compact()
                    ->visible(fn (Get $get): bool => filled($get('item_id'))
                        && self::showAssetDetails($get))
                    ->schema([
                        Select::make('inventory_unit_id')
                            ->label(fn (Get $get): string => 'Specific '.OwwaReferenceLabels::assetIdentifierLabel(
                                self::itemCategorySlug($get)
                            ))
                            ->options(fn (Get $get): array => $unitService->unitOptions(
                                self::intOrNull($get('item_id')),
                                self::intOrNull($get('office_id')),
                            ))
                            ->searchable()
                            ->live()
                            ->visible(fn (Get $get): bool => self::usesPropertyTracking($get)
                                && $unitService->hasAvailableUnits(
                                    self::intOrNull($get('item_id')),
                                    self::intOrNull($get('office_id')),
                                )
                                && $unitService->availableUnitsQuery(
                                    self::intOrNull($get('item_id')),
                                    self::intOrNull($get('office_id')),
                                )->count() > 1)
                            ->required(fn (Get $get): bool => self::requiresInventoryUnit($get))
                            ->helperText('Select the exact physical unit being disposed.')
                            ->columnSpanFull()
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $unit = InventoryUnit::query()->find($value);
                                    if ($unit?->status === InventoryUnit::STATUS_DISPOSED) {
                                        $fail('This inventory unit has already been disposed.');
                                    }
                                },
                            ])
                            ->afterStateUpdated(function ($state, Set $set, Get $get) use ($unitService, $syncItemOffice): void {
                                if (blank($state)) {
                                    $syncItemOffice($get, $set);

                                    return;
                                }

                                $unit = InventoryUnit::query()
                                    ->with(['issuance', 'acquisition'])
                                    ->find($state);

                                if ($unit === null) {
                                    return;
                                }

                                $unitService->applyUnitToFormState($unit, $set);
                            }),
                        TextInput::make('stock_number_display')
                            ->label(OwwaReferenceLabels::STOCK_NO)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ! self::usesPropertyTracking($get))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return;
                                }

                                $code = Item::query()->whereKey($itemId)->value('item_code');
                                $component->state(filled($code) ? $code : '—');
                            }),
                        TextInput::make('property_number')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::assetIdentifierLabel(
                                self::itemCategorySlug($get)
                            ))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::usesPropertyTracking($get))
                            ->dehydrated()
                            ->disabled(fn (Get $get): bool => filled($get('inventory_unit_id')))
                            ->placeholder('Asset tag / property no.')
                            ->helperText(fn (Get $get): string => filled($get('inventory_unit_id'))
                                ? 'Auto-filled from inventory.'
                                : (OwwaReferenceLabels::propertyNumberHelperText(self::itemCategorySlug($get)) ?: 'Enter the inventory item or property number.')),
                        TextInput::make('acquisition_cost')
                            ->label(fn (): string => self::isIirupCategory() ? 'Unit cost' : 'Acquisition cost')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->required(fn (): bool => self::isIirupCategory())
                            ->disabled(fn (Get $get): bool => (bool) $get('inventory_auto_synced') || filled($get('inventory_unit_id')))
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => (bool) $get('inventory_auto_synced') || filled($get('inventory_unit_id'))
                                ? 'Auto-filled from inventory.'
                                : (self::isIirupCategory()
                                    ? 'Required by '.(self::activeCategorySlug() === 'semi_expendable' ? 'IIRUSP' : 'IIRUP').'. Total cost is computed as quantity × unit cost.'
                                    : 'Enter acquisition cost if not available from records.')),
                        TextInput::make('accumulated_depreciation')
                            ->label('Accumulated depreciation')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('accumulated_impairment_losses')
                            ->label('Accumulated impairment losses')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->columnSpanFull()
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Disposal details')
                    ->columnSpanFull()
                    ->compact()
                    ->visible(fn (): bool => filled(self::activeCategorySlug()))
                    ->schema([
                        Select::make('disposal_mode')
                            ->label('Disposal mode')
                            ->options([
                                'destroyed' => 'Destroyed',
                                'sold_private' => 'Sold at private sale',
                                'sold_public' => 'Sold at public auction',
                                'transferred_without_cost' => 'Transferred without cost',
                            ])
                            ->placeholder('Select mode')
                            ->required(fn (): bool => self::activeCategorySlug() === 'consumables')
                            ->live()
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        TextInput::make('place_of_storage')
                            ->label('Place of storage')
                            ->maxLength(255)
                            ->required(fn (): bool => self::activeCategorySlug() === 'consumables')
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        Select::make('iirup_disposal_mode')
                            ->label('Disposal mode')
                            ->options([
                                'sale' => 'Sale',
                                'transfer' => 'Transfer',
                                'destruction' => 'Destruction',
                                'others' => 'Others',
                            ])
                            ->required(fn (): bool => self::isIirupCategory())
                            ->live()
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('iirup_disposal_amount')
                            ->label('Disposal amount')
                            ->helperText('Amount under the selected disposal mode (Sale, Transfer, Destruction, or Others).')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('iirup_other_mode')
                            ->label('Specify other disposal mode')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => $get('iirup_disposal_mode') === 'others')
                            ->visible(fn (Get $get): bool => self::isIirupCategory()
                                && $get('iirup_disposal_mode') === 'others')
                            ->columnSpanFull(),
                        TextInput::make('accountable_officer_designation')
                            ->label('Accountable officer designation')
                            ->maxLength(255)
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION
                            ))
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('accountable_officer_station')
                            ->label('Station / office')
                            ->maxLength(255)
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_STATION
                            ))
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('reason')
                            ->label('Reason')
                            ->placeholder('Why this item was disposed')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Sale details')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::activeCategorySlug() === 'consumables'
                        && ($get('disposal_mode') === 'sold_private' || $get('disposal_mode') === 'sold_public'))
                    ->schema([
                        TextInput::make('official_receipt_no')
                            ->label('Official receipt number')
                            ->maxLength(255)
                            ->required()
                            ->placeholder('—'),
                        DatePicker::make('sale_date')
                            ->label('Official receipt date')
                            ->required()
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        TextInput::make('sale_amount')
                            ->label('Official receipt amount')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Signatories')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        TextInput::make('custodian_printed_name')
                            ->label(fn (): string => match (self::activeCategorySlug()) {
                                'consumables' => 'Certified correct — Supply / Property Custodian',
                                default => 'Requested by — Accountable Officer',
                            })
                            ->maxLength(255)
                            ->required()
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_CUSTODIAN
                            ))
                            ->placeholder('Full name'),
                        TextInput::make('approved_by_printed_name')
                            ->label(fn (): string => self::activeCategorySlug() === 'consumables'
                                ? 'Disposal approved — Head / Authorized Representative'
                                : 'Approved by — Authorized Official')
                            ->maxLength(255)
                            ->required()
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_APPROVED
                            ))
                            ->placeholder('Full name'),
                        TextInput::make('authorized_official_designation')
                            ->label('Designation of authorized official')
                            ->maxLength(255)
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_DESIGNATION
                            ))
                            ->required(fn (): bool => self::isIirupCategory())
                            ->visible(fn (): bool => self::isIirupCategory()),
                        TextInput::make('inspection_officer_printed_name')
                            ->label('Certified correct — Inspection Officer')
                            ->maxLength(255)
                            ->required()
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_INSPECTION_OFFICER
                            ))
                            ->placeholder('Full name'),
                        TextInput::make('witness_printed_name')
                            ->label('Witness to disposal')
                            ->maxLength(255)
                            ->required()
                            ->datalist(fn (): array => \App\Models\ProcurementSignatoryName::suggestionsForRole(
                                \App\Models\ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS
                            ))
                            ->placeholder('Full name'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int, Hidden|Select|Placeholder>
     */
    protected static function officeFields(callable $afterStateUpdated): array
    {
        // Consumable WMR: regional SC warehouse only (issued office stock is already consumed).
        if (self::activeCategorySlug() === 'consumables') {
            return [
                Hidden::make('office_id')
                    ->default(fn (): ?int => app(SupplyOfficeResolver::class)->resolve())
                    ->dehydrated(),
                Placeholder::make('regional_office_display')
                    ->label('Office')
                    ->content(fn (): string => app(SupplyOfficeResolver::class)->resolveOfficeName()
                        ?? 'Regional supply office'),
            ];
        }

        if (CustodianOfficeScope::hasFixedInventoryOffice()) {
            return [
                Hidden::make('office_id')
                    ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                    ->dehydrated(),
                Placeholder::make('fixed_office_display')
                    ->label('Office')
                    ->content(function (): string {
                        $officeId = CustodianOfficeScope::inventoryOfficeId();

                        if ($officeId === null) {
                            return '—';
                        }

                        return Office::query()->whereKey($officeId)->value('name') ?? '—';
                    }),
            ];
        }

        return [
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
                ->dehydrated()
                ->live()
                ->afterStateUpdated($afterStateUpdated),
        ];
    }

    public static function defaultDisposalType(): ?string
    {
        $options = self::disposalTypeOptions();

        if ($options === []) {
            return null;
        }

        return array_key_first($options);
    }

    /**
     * @return array<string, string>
     */
    public static function disposalTypeOptions(): array
    {
        return match (self::activeCategorySlug()) {
            'consumables' => [
                'waste_sale' => 'Waste or sale (WMR)',
            ],
            'ppe' => [
                'unserviceable' => 'Unserviceable (IIRUP)',
            ],
            'semi_expendable' => [
                'unserviceable' => 'Unserviceable (IIRUSP)',
            ],
            default => [],
        };
    }

    protected static function showAssetDetails(Get $get): bool
    {
        return self::usesPropertyTracking($get)
            && $get('disposal_type') === 'unserviceable';
    }

    protected static function usesPropertyTracking(Get $get): bool
    {
        return OwwaReferenceLabels::usesPropertyNumber(self::itemCategorySlug($get));
    }

    protected static function isIirupCategory(): bool
    {
        return in_array(self::activeCategorySlug(), ['ppe', 'semi_expendable'], true);
    }

    protected static function requiresInventoryUnit(Get $get): bool
    {
        if (! self::usesPropertyTracking($get)) {
            return false;
        }

        $unitService = app(DisposalInventoryUnitService::class);

        return $unitService->hasAvailableUnits(
            self::intOrNull($get('item_id')),
            self::intOrNull($get('office_id')),
        ) && $unitService->availableUnitsQuery(
            self::intOrNull($get('item_id')),
            self::intOrNull($get('office_id')),
        )->count() > 1;
    }

    public static function resolvedCategorySlug(): ?string
    {
        return self::activeCategorySlug();
    }

    protected static function activeCategorySlug(): ?string
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

        if (blank($categoryId)) {
            return null;
        }

        return ItemCategory::query()->find((int) $categoryId)?->getTemplateSlug();
    }

    protected static function itemCategorySlug(Get $get): ?string
    {
        return OwwaReferenceLabels::itemCategorySlug(self::intOrNull($get('item_id')));
    }

    protected static function intOrNull(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) $value;
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
