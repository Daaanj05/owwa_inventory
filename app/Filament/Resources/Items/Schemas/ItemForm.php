<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\ItemCategory;
use App\Services\ReferenceCodeService;
use App\Support\ItemPropertyClass;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Filament\Facades\Filament;
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
                Section::make('Item details')
                    ->description('Basic information about this inventory item.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('item_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->default(fn (): ?int => self::activeCategoryId())
                            ->disabled(fn (): bool => self::isCategoryScoped())
                            ->dehydrated(true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('override_item_code')
                            ->label('Edit stock number manually')
                            ->default(false)
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'create'
                                && (Filament::auth()->user()?->canOverrideGeneratedCodes() ?? false)),
                        TextInput::make('item_code')
                            ->label('Stock number / item code')
                            ->maxLength(100)
                            ->disabled(fn (string $operation, Get $get): bool => $operation === 'create'
                                && ! ($get('override_item_code') ?? false)
                                && config('inventory.auto_generate_item_codes', true))
                            ->dehydrated()
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
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id')))
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
                        Select::make('property_class')
                            ->label('Property class')
                            ->options(ItemPropertyClass::options())
                            ->required()
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
                            ->helperText('Select the OWWA property tab for Annex A.1 / A.4 exports (e.g. ICT, Office equipment).')
                            ->visible(fn (Get $get): bool => self::isSemiExpendableCategory($get('item_category_id'))),
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
                        TextInput::make('serial_number')
                            ->label('Serial number')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::isPpeCategory($get('item_category_id')))
                            ->helperText('Enter the manufacturer serial or asset tag from the physical unit (not the RSMI report serial).')
                            ->required(fn (): bool => config('inventory.require_serial_number_for_ppe', true)),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
            ]);
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
            && filled(session('active_item_category_id'));
    }

    protected static function activeCategoryId(): ?int
    {
        if (! self::isCategoryScoped()) {
            return null;
        }

        return (int) session('active_item_category_id');
    }
}
