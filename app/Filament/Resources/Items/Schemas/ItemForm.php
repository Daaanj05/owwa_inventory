<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\ItemCategory;
use App\Support\ItemMeasurementUnitInput;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->heading(null)
                    ->compact()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Hidden::make('item_category_id')
                            ->default(fn (): ?int => self::activeCategoryId())
                            ->dehydrated(true)
                            ->visible(fn (string $operation): bool => $operation === 'edit'
                                || ($operation === 'create' && self::isCategoryScoped())),
                        Select::make('item_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->preload()
                            ->live()
                            ->default(fn (): ?int => self::activeCategoryId())
                            ->disabled(fn (): bool => self::isCategoryScoped())
                            ->dehydrated(true)
                            ->visible(fn (string $operation): bool => $operation === 'create' && ! self::isCategoryScoped())
                            ->columnSpanFull(),

                        TextInput::make('base_name')
                            ->label('Item family')
                            ->required()
                            ->maxLength(255)
                            ->datalist(function (Get $get): array {
                                $categoryId = filled($get('item_category_id'))
                                    ? (int) $get('item_category_id')
                                    : (int) (self::activeCategoryId() ?? 0);

                                return Item::familySuggestionsForCategory($categoryId);
                            })
                            ->live(onBlur: true)
                            ->helperText('Pick an existing name or type a new one (e.g. Bond Paper).')
                            ->rule(function (Get $get, ?Item $record = null) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                    $categoryId = filled($get('item_category_id'))
                                        ? (int) $get('item_category_id')
                                        : (int) (self::activeCategoryId() ?? 0);
                                    $baseName = Item::normalizeFamilyName((string) $value, $categoryId);
                                    $subItem = filled($get('sub_item')) ? trim((string) $get('sub_item')) : null;
                                    $name = Item::mergeDisplayName($baseName, $subItem);

                                    if ($name === '' || $categoryId <= 0) {
                                        return;
                                    }

                                    $existing = Item::query()
                                        ->active()
                                        ->where('item_category_id', $categoryId)
                                        ->when(
                                            $record instanceof Item,
                                            fn ($query) => $query->whereKeyNot($record->getKey()),
                                        )
                                        ->get(['name'])
                                        ->first(fn (Item $item): bool => mb_strtolower((string) $item->name) === mb_strtolower($name));

                                    if ($existing instanceof Item) {
                                        $fail("Item already exists in this category: {$existing->name}.");
                                    }
                                };
                            }),
                        TextInput::make('sub_item')
                            ->label('Variant')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText('Optional size or kind (e.g. A4, Long, Letter).'),
                        Placeholder::make('name_preview')
                            ->label('Catalog name')
                            ->content(fn (Get $get): string => Item::mergeDisplayName(
                                $get('base_name'),
                                $get('sub_item'),
                            ) ?: '—')
                            ->helperText('Saved as the catalog name on forms and reports.')
                            ->columnSpanFull(),
                        Hidden::make('name')
                            ->dehydrated(true)
                            ->dehydrateStateUsing(fn (Get $get): string => Item::mergeDisplayName(
                                $get('base_name'),
                                $get('sub_item'),
                            )),

                        TextInput::make('item_code')
                            ->label('Stock number / item code')
                            ->maxLength(100)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation, Get $get): bool => self::isConsumablesCategory($get('item_category_id'))
                                && $operation !== 'create')
                            ->helperText('Assigned automatically on create. Not editable.'),
                        TextInput::make('semi_expendable_property_number')
                            ->label('Inventory item no.')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Assigned automatically on save')
                            ->helperText('Catalog number for the first unit (TEMP-… until that unit’s cost finalizes SPLV/SPHV). Each additional stock unit takes the next sequence.')
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create'
                                && self::isSemiExpendableCategory($get('item_category_id'))),
                        TextInput::make('ppe_property_number')
                            ->label('Property No.')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Assigned automatically on save')
                            ->helperText('One Property No. per catalog PPE item.')
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create'
                                && self::isPpeCategory($get('item_category_id'))),
                        TextInput::make('value_type_display')
                            ->label('Value category (COA)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create'
                                && self::isSemiExpendableCategory($get('item_category_id')))
                            ->formatStateUsing(fn ($state, $record): string => $record
                                ? SemiExpendableValueCategory::labelForValueType($record->value_type)
                                : 'Set automatically from acquisition unit cost ('.SemiExpendableValueCategory::tierRuleSummary().')')
                            ->helperText('Low-valued (SPLV) or high-valued (SPHV) per COA Circular 2022-004. Not entered manually.'),

                        Select::make('unit')
                            ->label('Measurement unit')
                            ->required()
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->placeholder('Select an option')
                            ->searchable()
                            ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                                ItemAttributeOption::KIND_UNIT,
                                $get('unit'),
                            ))
                            ->createOptionForm([
                                TextInput::make('unit')
                                    ->label('Measurement unit')
                                    ->required()
                                    ->maxLength(50),
                            ])
                            ->createOptionUsing(function (array $data): string {
                                $unit = trim((string) ($data['unit'] ?? ''));

                                if (! ItemMeasurementUnitInput::isValid($unit)) {
                                    throw ValidationException::withMessages([
                                        'unit' => 'Measurement unit must be letters only (e.g. piece, ream, box).',
                                    ]);
                                }

                                ItemAttributeOption::query()->updateOrCreate(
                                    [
                                        'kind' => ItemAttributeOption::KIND_UNIT,
                                        'value' => $unit,
                                    ],
                                    [
                                        'label' => $unit,
                                        'is_active' => true,
                                    ],
                                );

                                return $unit;
                            })
                            ->helperText('Letters only — how quantity is counted (e.g. piece, ream, box).'),
                        TextInput::make('reorder_level')
                            ->label('Reorder point')
                            ->required(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id'))),

                        Select::make('inventory_type')
                            ->label('Inventory type')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->placeholder('Select an option')
                            ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                                ItemAttributeOption::KIND_INVENTORY_TYPE,
                                $get('inventory_type'),
                            ))
                            ->required(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_INVENTORY_TYPE,
                                $state,
                            ))
                            ->dehydrateStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_INVENTORY_TYPE,
                                $state,
                            ))
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if (blank($state)) {
                                    $set('inventory_type', null);

                                    return;
                                }

                                $resolved = ItemAttributeOption::resolveStoredValue(
                                    ItemAttributeOption::KIND_INVENTORY_TYPE,
                                    $state,
                                );
                                if ($resolved !== null && $resolved !== $state) {
                                    $set('inventory_type', $resolved);
                                }
                            })
                            ->rule(function (Get $get) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (! self::isConsumablesCategory($get('item_category_id')) || blank($value)) {
                                        return;
                                    }

                                    if (ItemAttributeOption::resolveStoredValue(ItemAttributeOption::KIND_INVENTORY_TYPE, $value) === null) {
                                        $fail('Inventory type must be on the item attribute list.');
                                    }
                                };
                            })
                            ->helperText('Pick an inventory type.'),
                        TextInput::make('days_to_consume')
                            ->label('Days to consume')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id'))),

                        Select::make('property_class')
                            ->label('Property class')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->placeholder('Select an option')
                            ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                                ItemAttributeOption::KIND_PROPERTY_CLASS,
                                $get('property_class'),
                            ))
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PROPERTY_CLASS,
                                $state,
                            ))
                            ->dehydrateStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PROPERTY_CLASS,
                                $state,
                            ))
                            ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                                if (blank($state) || ! self::isSemiExpendableCategory($get('item_category_id'))) {
                                    return;
                                }

                                $resolved = ItemAttributeOption::resolveStoredValue(
                                    ItemAttributeOption::KIND_PROPERTY_CLASS,
                                    $state,
                                );
                                if ($resolved !== null && $resolved !== $state) {
                                    $set('property_class', $resolved);
                                }

                                if ($resolved === null || filled($get('estimated_useful_life'))) {
                                    return;
                                }

                                $default = SemiExpendableUsefulLife::defaultForPropertyClass($resolved);
                                if ($default !== null) {
                                    $set('estimated_useful_life', $default);
                                }
                            })
                            ->rule(function (Get $get) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (! self::isSemiExpendableCategory($get('item_category_id')) || blank($value)) {
                                        return;
                                    }

                                    if (ItemAttributeOption::resolveStoredValue(ItemAttributeOption::KIND_PROPERTY_CLASS, $value) === null) {
                                        $fail('Property class must be on the item attribute list.');
                                    }
                                };
                            })
                            ->helperText('Pick an official COA label. Category code in Inventory item no. (IT, FF, OE, …).')
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id'))),
                        Select::make('ppe_type')
                            ->label('Type of PPE')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->placeholder('Select an option')
                            ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                                ItemAttributeOption::KIND_PPE_TYPE,
                                $get('ppe_type'),
                            ))
                            ->required(fn (Get $get): bool => self::isPpeCategory($get('item_category_id')))
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PPE_TYPE,
                                $state,
                            ))
                            ->dehydrateStateUsing(fn (mixed $state): ?string => ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PPE_TYPE,
                                $state,
                            ))
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (blank($state)) {
                                    $set('ppe_type', null);

                                    return;
                                }

                                $resolved = ItemAttributeOption::resolveStoredValue(
                                    ItemAttributeOption::KIND_PPE_TYPE,
                                    $state,
                                );
                                if ($resolved !== null && $resolved !== $state) {
                                    $set('ppe_type', $resolved);
                                }
                            })
                            ->rule(function (Get $get) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (! self::isPpeCategory($get('item_category_id')) || blank($value)) {
                                        return;
                                    }

                                    if (ItemAttributeOption::resolveStoredValue(ItemAttributeOption::KIND_PPE_TYPE, $value) === null) {
                                        $fail('Type of PPE must be on the item attribute list.');
                                    }
                                };
                            })
                            ->helperText('Pick an official COA label. Printed on Appendix 73 RPCPPE as Type of Property, Plant and Equipment.')
                            ->visible(fn (Get $get): bool => self::isPpeCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isPpeCategory($get('item_category_id'))),
                        Select::make('uacs_object_code_id')
                            ->label('UACS object code')
                            ->relationship(
                                name: 'uacsObjectCode',
                                titleAttribute: 'code',
                                modifyQueryUsing: fn ($query) => $query->active()->orderBy('code'),
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->optionLabel())
                            ->searchable(['code', 'name'])
                            ->preload()
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id'))
                                || self::isPpeCategory($get('item_category_id')))
                            ->helperText(fn (Get $get): string => self::isPpeCategory($get('item_category_id'))
                                ? 'CODE NUMBER segment (GL / UACS).'
                                : 'CODE NUMBER segment (GL / UACS). Maintained under System Admin → UACS object codes.')
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id'))
                                || self::isPpeCategory($get('item_category_id'))),
                        TextInput::make('estimated_useful_life')
                            ->label('Estimated useful life (months)')
                            ->placeholder('e.g. 36')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Months (e.g. 36 = 3 years). Must exceed 12 months for semi-expendable eligibility.')
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->columnSpanFull()
                            ->dehydrateStateUsing(fn ($state): ?string => SemiExpendableUsefulLife::storeFromMonths($state))
                            ->formatStateUsing(fn ($state): ?string => SemiExpendableUsefulLife::parseToMonths($state) !== null
                                ? (string) SemiExpendableUsefulLife::parseToMonths($state)
                                : (filled($state) ? (string) $state : null))
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if (! self::isSemiExpendableCategory($get('item_category_id')) || blank($value)) {
                                        return;
                                    }

                                    try {
                                        SemiExpendableUsefulLife::assertEligibleForSemi(
                                            SemiExpendableUsefulLife::storeFromMonths($value) ?? (string) $value,
                                        );
                                    } catch (\Illuminate\Validation\ValidationException $exception) {
                                        $fail($exception->validator->errors()->first('estimated_useful_life'));
                                    }
                                };
                            }),

                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(2)
                            ->helperText(fn (Get $get): ?string => self::isPpeCategory($get('item_category_id'))
                                ? 'Include brand, size, color, manufacturer serial or asset tag if any (maps to Description on PAR/PC export).'
                                : null),
                    ]),
            ]);
    }

    protected static function isConsumablesCategory(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::find($categoryId);

        return $category && $category->getTemplateSlug() === 'consumables';
    }

    protected static function isSemiExpendableCategory(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::find($categoryId);

        return $category && $category->getTemplateSlug() === 'semi_expendable';
    }

    protected static function isPpeCategory(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::find($categoryId);

        return $category && $category->getTemplateSlug() === 'ppe';
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (filled(request()->query('category')) || filled(session('active_item_category_id')));
    }

    protected static function activeCategoryId(): ?int
    {
        if (! self::isCategoryScoped()) {
            return null;
        }

        return SyncsActiveItemCategory::resolveCategoryIdFromContext();
    }
}
