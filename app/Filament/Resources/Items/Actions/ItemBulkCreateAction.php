<?php

namespace App\Filament\Resources\Items\Actions;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Forms\Components\StyledDatalistInput;
use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\UacsObjectCode;
use App\Services\BulkCreateItemsService;
use App\Support\ConsumableInventoryType;
use App\Support\ItemMeasurementUnitInput;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\SemiExpendableUsefulLife;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

class ItemBulkCreateAction
{
    public static function make(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('bulkCreateItems')
                ->label('Add Many Items')
                ->icon('heroicon-o-squares-plus')
                ->color('primary')
                ->modalHeading('Add Many Items')
                ->modalDescription(function (): string {
                    $category = self::currentCategory();

                    return $category
                        ? 'Create multiple catalog items for '.$category->name.'. Starting stock is optional per row and is assigned to the regional supply office.'
                        : 'Create multiple catalog items. Starting stock is optional per row and is assigned to the regional supply office.';
                })
                ->modalSubmitAction(false)
                ->extraModalFooterActions(fn (Action $action): array => [
                    ItemOpeningStockFields::confirmingSubmitAction(
                        $action,
                        'Create items',
                        'Create these items?',
                    ),
                ])
                ->visible(fn (): bool => self::currentCategoryId() > 0)
                ->fillForm(function (): array {
                    $categoryId = self::currentCategoryId();

                    return [
                        'item_category_id' => $categoryId,
                        'items' => [
                            self::emptyRow(),
                            self::emptyRow(),
                            self::emptyRow(),
                        ],
                    ];
                })
                ->schema(fn (): array => self::schema())
                ->action(function (array $data): void {
                    $categoryId = self::currentCategoryId();
                    $officeId = ItemOpeningStockFields::resolveRegionalOfficeId();

                    try {
                        $created = app(BulkCreateItemsService::class)->createMany(
                            $categoryId,
                            array_values($data['items'] ?? []),
                            $officeId,
                            auth()->user(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to create items')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();

                        throw $exception;
                    }

                    $count = count($created);
                    Notification::make()
                        ->title($count === 1 ? '1 item created' : "{$count} items created")
                        ->success()
                        ->send();
                }),
            OwwaFormModalDefaults::WIDTH_WIDE,
        );
    }

    protected static function currentCategoryId(): int
    {
        return SyncsActiveItemCategory::resolveCategoryIdFromContext();
    }

    protected static function currentCategory(): ?ItemCategory
    {
        $categoryId = self::currentCategoryId();

        if ($categoryId <= 0) {
            return null;
        }

        return ItemCategory::query()->find($categoryId);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyRow(): array
    {
        return [
            'base_name' => null,
            'sub_item' => null,
            'unit' => 'piece',
            'reorder_level' => 0,
            'days_to_consume' => null,
            'inventory_type' => null,
            'property_class' => null,
            'ppe_type' => null,
            'uacs_object_code_id' => null,
            'estimated_useful_life' => null,
            'description' => null,
            ItemOpeningStockFields::QUANTITY_KEY => null,
            ItemOpeningStockFields::UNIT_COST_KEY => null,
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected static function schema(): array
    {
        $category = self::currentCategory();
        $categoryId = $category?->id ?? 0;
        $slug = $category?->getTemplateSlug() ?? 'consumables';

        [$tableColumns, $rowFields] = match ($slug) {
            'semi_expendable' => self::semiExpendableTable($categoryId),
            'ppe' => self::ppeTable($categoryId),
            default => self::consumablesTable($categoryId),
        };

        return [
            Hidden::make('item_category_id')->dehydrated(),
            Placeholder::make('category_label')
                ->label('Category')
                ->content(fn (): string => self::currentCategory()?->name ?? '—'),
            Repeater::make('items')
                ->hiddenLabel()
                ->addActionLabel('Add Row')
                ->defaultItems(3)
                ->minItems(1)
                ->reorderable(false)
                ->cloneable()
                ->table($tableColumns)
                ->compact()
                ->schema($rowFields)
                ->extraAttributes(['class' => 'owwa-bulk-items-repeater'])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array{0: array<int, TableColumn>, 1: array<int, \Filament\Forms\Components\Component>}
     */
    protected static function consumablesTable(int $categoryId): array
    {
        return [
            [
                TableColumn::make('Base Item')->markAsRequired()->width('14%'),
                TableColumn::make('Sub-Item')->width('10%'),
                TableColumn::make('Unit')->markAsRequired()->width('7rem'),
                TableColumn::make('Reorder Point')->markAsRequired()->width('7rem'),
                TableColumn::make('Inventory Type')->markAsRequired()->width('12%'),
                TableColumn::make('Days To Consume')->width('7rem'),
                TableColumn::make('Starting Qty')->width('6.5rem'),
                TableColumn::make('Unit Cost')->width('7rem'),
                TableColumn::make('Description')->width('16%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                Select::make('inventory_type')
                    ->hiddenLabel()
                    ->options(ConsumableInventoryType::optionsWithUsed())
                    ->searchable(),
                TextInput::make('days_to_consume')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0),
                ItemOpeningStockFields::bulkQuantityField(),
                ItemOpeningStockFields::bulkUnitCostField(requiredWhenQuantity: false),
                TextInput::make('description')
                    ->hiddenLabel()
                    ->maxLength(500),
            ],
        ];
    }

    /**
     * @return array{0: array<int, TableColumn>, 1: array<int, \Filament\Forms\Components\Component>}
     */
    protected static function semiExpendableTable(int $categoryId): array
    {
        return [
            [
                TableColumn::make('Base Item')->markAsRequired()->width('12%'),
                TableColumn::make('Sub-Item')->width('8%'),
                TableColumn::make('Unit')->markAsRequired()->width('6.5rem'),
                TableColumn::make('Reorder Point')->markAsRequired()->width('6.5rem'),
                TableColumn::make('Property Class')->markAsRequired()->width('11%'),
                TableColumn::make('UACS Object Code')->markAsRequired()->width('12%'),
                TableColumn::make('Estimated Useful Life')->markAsRequired()->width('9%'),
                TableColumn::make('Starting Qty')->width('6.5rem'),
                TableColumn::make('Unit Cost')->markAsRequired()->width('7rem'),
                TableColumn::make('Description')->width('14%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                Select::make('property_class')
                    ->hiddenLabel()
                    ->options(ItemPropertyClass::options())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                        if (blank($state) || filled($get('estimated_useful_life'))) {
                            return;
                        }

                        $default = SemiExpendableUsefulLife::defaultForPropertyClass($state);
                        if ($default !== null) {
                            $set('estimated_useful_life', $default);
                        }
                    }),
                Select::make('uacs_object_code_id')
                    ->hiddenLabel()
                    ->options(fn (): array => self::uacsOptions())
                    ->searchable(),
                TextInput::make('estimated_useful_life')
                    ->hiddenLabel()
                    ->placeholder('Months, e.g. 36'),
                ItemOpeningStockFields::bulkQuantityField(),
                ItemOpeningStockFields::bulkUnitCostField(),
                TextInput::make('description')
                    ->hiddenLabel()
                    ->maxLength(500),
            ],
        ];
    }

    /**
     * @return array{0: array<int, TableColumn>, 1: array<int, \Filament\Forms\Components\Component>}
     */
    protected static function ppeTable(int $categoryId): array
    {
        return [
            [
                TableColumn::make('Base Item')->markAsRequired()->width('13%'),
                TableColumn::make('Sub-Item')->width('8%'),
                TableColumn::make('Unit')->markAsRequired()->width('6.5rem'),
                TableColumn::make('Reorder Point')->markAsRequired()->width('6.5rem'),
                TableColumn::make('Type of PPE')->markAsRequired()->width('13%'),
                TableColumn::make('UACS Object Code')->markAsRequired()->width('13%'),
                TableColumn::make('Starting Qty')->width('6.5rem'),
                TableColumn::make('Unit Cost')->markAsRequired()->width('7rem'),
                TableColumn::make('Description')->width('16%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                Select::make('ppe_type')
                    ->hiddenLabel()
                    ->options(PpePropertyType::options())
                    ->searchable(),
                Select::make('uacs_object_code_id')
                    ->hiddenLabel()
                    ->options(fn (): array => self::uacsOptions())
                    ->searchable(),
                ItemOpeningStockFields::bulkQuantityField(),
                ItemOpeningStockFields::bulkUnitCostField(),
                TextInput::make('description')
                    ->hiddenLabel()
                    ->maxLength(500),
            ],
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function commonLeadingFields(int $categoryId): array
    {
        return [
            StyledDatalistInput::make('base_name')
                ->hiddenLabel()
                ->maxLength(255)
                ->suggestions(fn (): array => self::baseItemSuggestions($categoryId)),
            TextInput::make('sub_item')
                ->hiddenLabel()
                ->maxLength(255),
            ItemMeasurementUnitInput::configure(
                TextInput::make('unit')
                    ->hiddenLabel()
                    ->default('piece')
                    ->maxLength(50),
            ),
            TextInput::make('reorder_level')
                ->hiddenLabel()
                ->numeric()
                ->default(0)
                ->minValue(0),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected static function uacsOptions(): array
    {
        return UacsObjectCode::query()
            ->active()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (UacsObjectCode $code): array => [
                $code->id => $code->optionLabel(),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function baseItemSuggestions(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        return Item::query()
            ->active()
            ->where('item_category_id', $categoryId)
            ->orderByRaw('COALESCE(base_name, name)')
            ->get(['base_name', 'name'])
            ->map(function (Item $item): string {
                return filled($item->base_name) ? (string) $item->base_name : (string) $item->name;
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
