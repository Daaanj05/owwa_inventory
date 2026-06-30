<?php

namespace App\Filament\Resources\Issuances\Schemas;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\User;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\IssuanceSignatoryLabels;
use App\Support\OwwaReferenceLabels;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IssuanceForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitConsolidator = $user && $user->isUnitConsolidator();
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Item & quantity')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(fn (): string => OwwaReferenceLabels::issuanceControl())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        TextInput::make('linked_ris_no')
                            ->label(OwwaReferenceLabels::RIS)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->placeholder('—')
                            ->helperText(fn (Get $get): ?string => filled($get('requisition_id'))
                                ? 'Linked via Requisitions → Record issuance (RSMI). Cannot be changed here.'
                                : 'No requisition linked — RSMI export will leave RIS No. blank (ad-hoc issue).')
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                if (filled($state)) {
                                    return;
                                }

                                $requisitionId = $get('requisition_id');
                                if (blank($requisitionId)) {
                                    $component->state('—');

                                    return;
                                }

                                $code = Requisition::query()->whereKey($requisitionId)->value('reference_code');
                                $component->state(filled($code) ? $code : '—');
                            })
                            ->columnSpanFull(),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => cache()->remember(
                                'item_categories.options',
                                3600,
                                fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                            ))
                            ->placeholder('All categories')
                            ->default(fn (): mixed => session('active_item_category_id'))
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('item_id', null);
                                $set('measurement_unit_preview', null);
                            }),
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
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                if (blank($state)) {
                                    $set('measurement_unit_preview', null);
                                    $set('unit_cost', null);
                                    $set('amount', null);

                                    return;
                                }

                                $unit = Item::query()->whereKey($state)->value('unit');
                                $set('measurement_unit_preview', filled($unit) ? $unit : '—');

                                $item = Item::query()
                                    ->whereKey($state)
                                    ->first(['estimated_useful_life', 'property_class']);

                                $unitCost = Acquisition::query()
                                    ->where('item_id', $state)
                                    ->orderByDesc('acquisition_date')
                                    ->value('unit_cost');

                                $set('unit_cost', $unitCost !== null ? (float) $unitCost : null);
                                $set('estimated_useful_life', SemiExpendableUsefulLife::resolveForItem($item));

                                $quantity = (float) ($get('quantity') ?? 0);
                                if ($unitCost !== null && $quantity > 0) {
                                    $set('amount', $quantity * (float) $unitCost);
                                } else {
                                    $set('amount', null);
                                }
                            }),
                        TextInput::make('measurement_unit_preview')
                            ->label('Measurement unit')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('item_id')))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                if (filled($state)) {
                                    return;
                                }

                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return;
                                }

                                $unit = Item::query()->whereKey($itemId)->value('unit');
                                $component->state(filled($unit) ? $unit : '—');
                            }),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->live(debounce: '500ms')
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $quantity = $state !== null ? (float) $state : 0;
                                $unitCost = (float) ($get('unit_cost') ?? 0);

                                $set('amount', $quantity > 0 && $unitCost > 0 ? round($quantity * $unitCost, 2) : null);
                            }),
                        TextInput::make('unit_cost')
                            ->label('Unit cost (₱ per measurement unit)')
                            ->numeric()
                            ->prefix('₱')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Auto-filled from the latest acquisition.'),
                        TextInput::make('amount')
                            ->label('Total amount')
                            ->numeric()
                            ->prefix('₱')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Quantity × unit cost (per measurement unit).'),
                        DatePicker::make('issuance_date')
                            ->label('Issuance date')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make('Office & recipient')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                function (Builder $query) use ($scopeActive, $isUnitConsolidator, $user) {
                                    $query = $scopeActive($query);
                                    if ($isUnitConsolidator && $user->office_id) {
                                        $query->where('id', $user->office_id);
                                    }

                                    return $query;
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default($isUnitConsolidator ? $user->office_id : null),
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship(
                                'department',
                                'name',
                                function (Builder $query) use ($scopeActive, $isUnitConsolidator, $user) {
                                    $query = $scopeActive($query);
                                    if ($isUnitConsolidator && $user->office_id) {
                                        $query->where('office_id', $user->office_id);
                                    }

                                    return $query;
                                }
                            )
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->placeholder('—')
                            ->helperText(fn (Get $get): ?string => self::isSemiExpendableCategory($get('item_category_filter'))
                                ? 'Required for semi-expendable — department code becomes the custodian/location segment in the property number.'
                                : null),
                        Select::make('issued_to')
                            ->label('Issued to')
                            ->relationship(
                                'issuedTo',
                                'name',
                                fn (Builder $query) => $isUnitConsolidator && $user->office_id
                                    ? $query->where('office_id', $user->office_id)->where('role', User::ROLE_EMPLOYEE)
                                    : $query
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('—'),
                    ])
                    ->columns(2),

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
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => OwwaReferenceLabels::usesPropertyNumber(
                                OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                            ))
                            ->helperText(fn (Get $get): string => OwwaReferenceLabels::propertyNumberHelperText(
                                OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                            )),
                        TextInput::make('semi_property_number_preview')
                            ->label('Next inventory item no. (preview)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter'))
                                && filled($get('item_id'))
                                && filled($get('department_id')))
                            ->formatStateUsing(function ($state, Get $get): string {
                                $issuance = new Issuance([
                                    'item_id' => (int) $get('item_id'),
                                    'office_id' => (int) $get('office_id'),
                                    'department_id' => (int) $get('department_id'),
                                    'unit_cost' => filled($get('unit_cost')) ? (float) $get('unit_cost') : null,
                                    'issuance_date' => $get('issuance_date') ?? now(),
                                ]);

                                return app(SemiExpendablePropertyNumberBuilder::class)->previewNext($issuance);
                            })
                            ->helperText('Format: SPLV/SPHV-Year-SupplyType-UACS-DeptCode-Seq (assigned on save).'),
                        TextInput::make('semi_value_category_preview')
                            ->label('Value category (COA)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter'))
                                && filled($get('unit_cost')))
                            ->formatStateUsing(fn ($state, Get $get): string => SemiExpendableValueCategory::labelForUnitCost(
                                filled($get('unit_cost')) ? (float) $get('unit_cost') : null,
                            ))
                            ->helperText(SemiExpendableValueCategory::tierRuleSummary()),
                        TextInput::make('estimated_useful_life')
                            ->label('Estimated useful life')
                            ->placeholder('e.g. 5 yrs')
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter')))
                            ->helperText(SemiExpendableUsefulLife::labelSummary())
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter')))
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if (! self::isSemiExpendableCategory($get('item_category_filter'))) {
                                        return;
                                    }

                                    try {
                                        SemiExpendableUsefulLife::assertEligibleForSemi($value);
                                    } catch (\Illuminate\Validation\ValidationException $exception) {
                                        $fail($exception->validator->errors()->first('estimated_useful_life'));
                                    }
                                };
                            }),
                        TextInput::make('received_from_name')
                            ->label('Received from')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_filter'))),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(2)
                            ->placeholder('Any notes'),
                    ])
                    ->columns(2),

                Section::make('Signatories')
                    ->description(function (Get $get): string {
                        $slug = self::categorySlug($get('item_category_filter'));

                        return IssuanceSignatoryLabels::forCategorySlug($slug)['section_description'];
                    })
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('custodian_printed_name')
                            ->label(fn (Get $get): string => IssuanceSignatoryLabels::forCategorySlug(
                                self::categorySlug($get('item_category_filter'))
                            )['custodian'])
                            ->maxLength(255),
                        TextInput::make('custodian_designation')
                            ->label(fn (Get $get): string => IssuanceSignatoryLabels::forCategorySlug(
                                self::categorySlug($get('item_category_filter'))
                            )['custodian_designation'])
                            ->maxLength(255),
                        TextInput::make('issued_to_designation')
                            ->label(fn (Get $get): string => IssuanceSignatoryLabels::forCategorySlug(
                                self::categorySlug($get('item_category_filter'))
                            )['issued_to_designation'])
                            ->maxLength(255),
                        TextInput::make('accounting_staff_printed_name')
                            ->label(fn (Get $get): string => IssuanceSignatoryLabels::forCategorySlug(
                                self::categorySlug($get('item_category_filter'))
                            )['accounting_staff'])
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_filter'))),
                    ])
                    ->columns(2),
            ]);
    }

    protected static function categorySlug(mixed $categoryId): ?string
    {
        if (blank($categoryId)) {
            return null;
        }

        $category = ItemCategory::find($categoryId);

        return $category?->getTemplateSlug();
    }

    protected static function isSemiExpendableCategory(mixed $categoryId): bool
    {
        return self::categoryHasTemplateSlug($categoryId, 'semi_expendable');
    }

    protected static function isConsumablesCategory(mixed $categoryId): bool
    {
        return self::categoryHasTemplateSlug($categoryId, 'consumables');
    }

    protected static function categoryHasTemplateSlug(mixed $categoryId, string $slug): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::query()->find($categoryId);

        return $category && $category->getTemplateSlug() === $slug;
    }
}
