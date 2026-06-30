<?php

namespace App\Filament\Resources\Disposals\Schemas;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\DisposalInventoryUnitService;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaReferenceLabels;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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

                Section::make('Record disposal')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(fn (): string => OwwaReferenceLabels::disposal(self::activeCategorySlug()))
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        DatePicker::make('disposal_date')
                            ->label('Disposal date')
                            ->required()
                            ->default(now()),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => cache()->remember(
                                'item_categories.options',
                                3600,
                                fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                            ))
                            ->placeholder('All categories')
                            ->default(fn (): ?int => self::activeCategoryFilter())
                            ->visible(fn (): bool => ! self::isCategoryScoped())
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set) use ($unitService): void {
                                $set('item_id', null);
                                $unitService->clearUnitLinkedFields($set);
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
                            ->afterStateUpdated($syncItemOffice),
                        ...self::officeFields($syncItemOffice),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('reason')
                            ->label('Reason')
                            ->placeholder('Why this item was disposed')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Asset details')
                    ->columnSpanFull()
                    ->compact()
                    ->visible(fn (Get $get): bool => filled($get('item_id'))
                        && self::showAssetDetails($get))
                    ->schema([
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
                            ->placeholder('Asset tag / property no.')
                            ->helperText(fn (Get $get): string => OwwaReferenceLabels::propertyNumberHelperText(self::itemCategorySlug($get)) ?: 'Enter the inventory item or property number.'),
                        TextInput::make('acquisition_cost')
                            ->label('Acquisition cost')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->disabled(fn (Get $get): bool => (bool) $get('inventory_auto_synced'))
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => (bool) $get('inventory_auto_synced')
                                ? 'Auto-filled from inventory.'
                                : 'Enter acquisition cost if not available from records.'),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->columnSpanFull()
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Form details')
                    ->columnSpanFull()
                    ->compact()
                    ->visible(fn (): bool => filled(self::activeCategorySlug()))
                    ->schema([
                        TextInput::make('place_of_storage')
                            ->label('Place of storage')
                            ->maxLength(255)
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        Select::make('disposal_mode')
                            ->label('Disposal mode')
                            ->options([
                                'destroyed' => 'Destroyed',
                                'sold_private' => 'Sold at private sale',
                                'sold_public' => 'Sold at public auction',
                                'transferred_without_cost' => 'Transferred without cost',
                            ])
                            ->placeholder('Select mode')
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        TextInput::make('wmr_inspection_item_no')
                            ->label('Inspection item number')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        Select::make('iirup_disposal_mode')
                            ->label('Disposal mode')
                            ->options([
                                'sale' => 'Sale',
                                'transfer' => 'Transfer',
                                'destruction' => 'Destruction',
                                'others' => 'Others',
                            ])
                            ->visible(fn (): bool => in_array(self::activeCategorySlug(), ['ppe', 'semi_expendable'], true)),
                        TextInput::make('accountable_officer_designation')
                            ->label('Accountable officer designation')
                            ->maxLength(255)
                            ->visible(fn (): bool => in_array(self::activeCategorySlug(), ['ppe', 'semi_expendable'], true)),
                        TextInput::make('accountable_officer_station')
                            ->label('Station / office')
                            ->maxLength(255)
                            ->visible(fn (): bool => in_array(self::activeCategorySlug(), ['ppe', 'semi_expendable'], true)),
                    ])
                    ->columns(2),

                Section::make('Sale details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('official_receipt_no')
                            ->label('Official receipt number')
                            ->maxLength(255)
                            ->placeholder('—'),
                        DatePicker::make('sale_date')
                            ->label('Date of sale'),
                        TextInput::make('sale_amount')
                            ->label('Sale amount')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Signatories')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        TextInput::make('custodian_printed_name')
                            ->label(fn (): string => match (self::activeCategorySlug()) {
                                'consumables' => 'Prepared by (custodian)',
                                default => 'Custodian / accountable officer',
                            })
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('approved_by_printed_name')
                            ->label('Approved by')
                            ->maxLength(255)
                            ->placeholder('Full name')
                            ->visible(fn (): bool => self::activeCategorySlug() === 'consumables'),
                        TextInput::make('inspection_officer_printed_name')
                            ->label('Inspection officer')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('witness_printed_name')
                            ->label('Witness')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<int, Hidden|Select>
     */
    protected static function officeFields(callable $afterStateUpdated): array
    {
        if (CustodianOfficeScope::hasFixedInventoryOffice()) {
            return [
                Hidden::make('office_id')
                    ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                    ->dehydrated(),
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
            'ppe', 'semi_expendable' => [
                'unserviceable' => 'Unserviceable (IIRUP)',
            ],
            default => [],
        };
    }

    protected static function showAssetDetails(Get $get): bool
    {
        if (self::usesPropertyTracking($get) && $get('disposal_type') === 'unserviceable') {
            return true;
        }

        return ! self::usesPropertyTracking($get);
    }

    protected static function usesPropertyTracking(Get $get): bool
    {
        return OwwaReferenceLabels::usesPropertyNumber(self::itemCategorySlug($get));
    }

    protected static function activeCategorySlug(): ?string
    {
        $categoryId = session('active_item_category_id');

        if (blank($categoryId) && filled(request()->query('category'))) {
            $categoryId = (int) request()->query('category');
        }

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
