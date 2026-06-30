<?php

namespace App\Filament\Resources\Acquisitions\Schemas;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\OwwaReferenceLabels;
use App\Support\PpeValueCategory;
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

class AcquisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Acquisition details')
                    ->description(fn (Get $get): string => self::isPpeCategory($get('item_category_filter'))
                        ? 'Record PPE received after PR/PO/IAR paperwork and delivery. Use the PR / PO / IAR tab under Acquisitions for purchase forms. The full Property Card is on Stock levels.'
                        : 'Record goods after PR/PO/IAR paperwork and delivery. Use the PR / PO / IAR tab under Acquisitions for purchase forms. Stock No. comes from the selected item.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::acquisition())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => cache()->remember(
                                'item_categories.options',
                                3600,
                                fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                            ))
                            ->placeholder('All categories')
                            ->live()
                            ->dehydrated(false)
                            ->default(fn (): ?int => self::activeCategoryFilter())
                            ->disabled(fn (): bool => self::isCategoryScoped())
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
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (blank($state)) {
                                    $set('measurement_unit_preview', null);

                                    return;
                                }

                                $unit = Item::query()->whereKey($state)->value('unit');
                                $set('measurement_unit_preview', filled($unit) ? $unit : '—');
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
                            ->minValue(1),
                        TextInput::make('unit_cost')
                            ->label('Unit cost (₱ per measurement unit)')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->live(debounce: '500ms')
                            ->required(function (Get $get): bool {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return false;
                                }

                                $slug = Item::with('category')->find($itemId)?->category?->getTemplateSlug();

                                return in_array($slug, ['semi_expendable', 'ppe'], true);
                            })
                            ->helperText(function (Get $get): string {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return 'Price for one measurement unit — used to auto-fill issuance pricing.';
                                }

                                $slug = Item::with('category')->find($itemId)?->category?->getTemplateSlug();

                                return match ($slug) {
                                    'semi_expendable' => SemiExpendableValueCategory::tierRuleSummary(),
                                    'ppe' => PpeValueCategory::minimumRuleSummary(),
                                    default => 'Price for one measurement unit — used to auto-fill issuance pricing.',
                                };
                            })
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if ($value === null || $value === '') {
                                        return;
                                    }

                                    $itemId = $get('item_id');
                                    if (blank($itemId)) {
                                        return;
                                    }

                                    $item = Item::with('category')->find($itemId);
                                    $slug = $item?->category?->getTemplateSlug();

                                    try {
                                        if ($slug === 'semi_expendable') {
                                            SemiExpendableValueCategory::assertWithinSemiCap((float) $value);
                                        }

                                        if ($slug === 'ppe') {
                                            PpeValueCategory::assertMinimumForPpe((float) $value);
                                        }
                                    } catch (\Illuminate\Validation\ValidationException $e) {
                                        $fail($e->validator->errors()->first('unit_cost') ?? 'Invalid unit cost for this category.');
                                    }
                                };
                            }),
                        TextInput::make('semi_value_category_preview')
                            ->label('Value category (COA)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(function (Get $get): bool {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return false;
                                }

                                return Item::query()
                                    ->whereKey($itemId)
                                    ->whereHas('category', fn ($q) => $q->whereIn('name', ['Semi-Expendable', 'Semi Expendable', 'semi_expendable']))
                                    ->exists();
                            })
                            ->formatStateUsing(fn ($state, Get $get): string => SemiExpendableValueCategory::labelForUnitCost(
                                filled($get('unit_cost')) ? (float) $get('unit_cost') : null,
                            ))
                            ->helperText(SemiExpendableValueCategory::tierRuleSummary()),
                        DatePicker::make('acquisition_date')
                            ->label('Date')
                            ->required(),
                        TextInput::make('source')
                            ->label('Source')
                            ->placeholder('e.g. Supplier name, procurement reference')
                            ->maxLength(255)
                            ->helperText(fn (Get $get): ?string => self::isPpeCategory($get('item_category_filter'))
                                ? 'Supplier or procurement reference from offline purchase documents.'
                                : null),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->columnSpanFull()
                            ->rows(2)
                            ->placeholder('Any notes'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    protected static function isPpeCategory(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::query()->find($categoryId);

        return $category?->getTemplateSlug() === 'ppe';
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && filled(session('active_item_category_id'));
    }

    protected static function activeCategoryFilter(): ?int
    {
        if (! self::isCategoryScoped()) {
            return null;
        }

        return (int) session('active_item_category_id');
    }
}
