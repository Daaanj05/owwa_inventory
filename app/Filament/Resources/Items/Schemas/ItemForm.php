<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Forms\Components\StyledDatalistInput;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\ReferenceCodeService;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
                            ->visible(fn (string $operation): bool => $operation === 'create' && self::isCategoryScoped()),
                        Select::make('item_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->default(fn (): ?int => self::activeCategoryId())
                            ->disabled(fn (): bool => self::isCategoryScoped())
                            ->dehydrated(true)
                            ->visible(fn (string $operation): bool => $operation !== 'create' || ! self::isCategoryScoped()),
                        StyledDatalistInput::make('base_name')
                            ->label('Base item')
                            ->required()
                            ->maxLength(255)
                            ->suggestions(fn (Get $get): array => array_values(self::baseItemOptions($get('item_category_id'))))
                            ->live(onBlur: true)
                            ->helperText('Type to filter existing base items, or enter a new one. Saved with the item on create.'),
                        TextInput::make('sub_item')
                            ->label('Sub-item')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText('Optional variant (e.g. A4, Long, Blue). Each sub-item is a separate catalog row with its own stock/property number.'),
                        Placeholder::make('name_preview')
                            ->label('Item name')
                            ->content(fn (Get $get): string => Item::mergeDisplayName(
                                $get('base_name'),
                                $get('sub_item'),
                            ) ?: '—')
                            ->helperText('Saved as the catalog name used on forms and reports.'),
                        Hidden::make('name')
                            ->dehydrated(true)
                            ->dehydrateStateUsing(fn (Get $get): string => Item::mergeDisplayName(
                                $get('base_name'),
                                $get('sub_item'),
                            )),
                        Toggle::make('override_item_code')
                            ->label('Edit stock number manually')
                            ->default(false)
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create'
                                && self::isConsumablesCategory($get('item_category_id'))
                                && (Filament::auth()->user()?->canOverrideGeneratedCodes() ?? false)),
                        TextInput::make('item_code')
                            ->label('Stock number / item code')
                            ->maxLength(100)
                            ->disabled(fn (string $operation, Get $get): bool => $operation === 'create'
                                && ! ($get('override_item_code') ?? false)
                                && config('inventory.auto_generate_item_codes', true))
                            ->dehydrated()
                            ->visible(fn (string $operation, Get $get): bool => self::isConsumablesCategory($get('item_category_id'))
                                && ($operation !== 'create' || ! config('inventory.auto_generate_item_codes', true)))
                            ->helperText(fn (string $operation, Get $get): string => self::itemCodeHelperText($operation, $get)),
                        TextInput::make('unit')
                            ->label('Measurement unit')
                            ->required()
                            ->default('piece')
                            ->maxLength(50)
                            ->helperText('How quantity is counted on OWWA forms (e.g. piece, ream, box).'),
                        TextInput::make('value_type_display')
                            ->label('Value category (COA)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create'
                                && self::isSemiExpendableCategory($get('item_category_id')))
                            ->formatStateUsing(fn ($state, $record): string => $record
                                ? \App\Support\SemiExpendableValueCategory::labelForValueType($record->value_type)
                                : 'Set automatically from acquisition unit cost ('.SemiExpendableValueCategory::tierRuleSummary().')')
                            ->helperText('Low-valued (SPLV) or high-valued (SPHV) per COA Circular 2022-004 — '.SemiExpendableValueCategory::tierRuleSummary().'. Not entered manually.'),
                        TextInput::make('reorder_level')
                            ->label('Reorder point')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('days_to_consume')
                            ->label('Days to consume')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id'))),
                        Select::make('inventory_type')
                            ->label('Inventory type')
                            ->options(ConsumableInventoryType::options())
                            ->required(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->searchable()
                            ->helperText('Printed on Appendix 66 RPCI as Type of Inventory Item.')
                            ->visible(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isConsumablesCategory($get('item_category_id'))),
                        Select::make('property_class')
                            ->label('Property class')
                            ->options(ItemPropertyClass::options())
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                if (blank($state) || ! self::isSemiExpendableCategory($get('item_category_id'))) {
                                    return;
                                }

                                if (blank($get('estimated_useful_life'))) {
                                    $default = SemiExpendableUsefulLife::defaultForPropertyClass($state);
                                    if ($default !== null) {
                                        $set('estimated_useful_life', $default);
                                    }
                                }
                            })
                            ->helperText('Category code in Inventory item no. (IT, FF, OE, …).')
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->dehydrated(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id'))),
                        Select::make('ppe_type')
                            ->label('Type of PPE')
                            ->options(PpePropertyType::options())
                            ->required(fn (Get $get): bool => self::isPpeCategory($get('item_category_id')))
                            ->searchable()
                            ->helperText('Printed on Appendix 73 RPCPPE as Type of Property, Plant and Equipment.')
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
                        TextInput::make('semi_expendable_property_number')
                            ->label('Inventory item no.')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Assigned automatically on save')
                            ->helperText('Catalog Inventory item no. (TEMP-… until first acquisition cost finalizes SPLV/SPHV).')
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
                        TextInput::make('estimated_useful_life')
                            ->label('Estimated useful life')
                            ->placeholder('e.g. 5 yrs')
                            ->helperText(SemiExpendableUsefulLife::labelSummary())
                            ->required(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if (! self::isSemiExpendableCategory($get('item_category_id')) || blank($value)) {
                                        return;
                                    }

                                    try {
                                        SemiExpendableUsefulLife::assertEligibleForSemi($value);
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

    /**
     * @return array<string, string>
     */
    protected static function baseItemOptions(mixed $categoryId): array
    {
        $resolvedCategoryId = filled($categoryId)
            ? (int) $categoryId
            : self::activeCategoryId();

        if (! $resolvedCategoryId) {
            return [];
        }

        return Item::query()
            ->active()
            ->where('item_category_id', $resolvedCategoryId)
            ->orderByRaw('COALESCE(base_name, name)')
            ->get(['base_name', 'name'])
            ->mapWithKeys(function (Item $item): array {
                $base = filled($item->base_name) ? (string) $item->base_name : (string) $item->name;

                return [$base => $base];
            })
            ->unique()
            ->sortKeys()
            ->all();
    }

    protected static function itemCodeHelperText(string $operation, Get $get): string
    {
        if ($operation !== 'create' || ! config('inventory.auto_generate_item_codes', true)) {
            return 'Permanent catalog identifier (Stock No. on OWWA forms).';
        }

        if ($get('override_item_code')) {
            return 'Manual override enabled. Use only for exceptions approved by your supervisor.';
        }

        $preview = app(ReferenceCodeService::class)->previewItemCodeForCategoryId(
            $get('item_category_id') ? (int) $get('item_category_id') : null,
        );

        if ($preview !== '') {
            return "Next stock number on save: {$preview}";
        }

        return 'Select a category to preview the next stock number. The system assigns it automatically on save.';
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
