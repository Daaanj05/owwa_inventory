<?php

namespace App\Filament\Resources\Items\Actions;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\ItemCategory;
use App\Models\UacsObjectCode;
use App\Services\BulkCreateItemsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                        ? 'Create multiple catalog items for '.$category->name.'.'
                        : 'Create multiple catalog items.';
                })
                ->modalSubmitActionLabel('Create items')
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

                    try {
                        $created = app(BulkCreateItemsService::class)->createMany(
                            $categoryId,
                            array_values($data['items'] ?? []),
                            null,
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
            'unit' => null,
            'reorder_level' => 0,
            'days_to_consume' => null,
            'inventory_type' => null,
            'property_class' => null,
            'ppe_type' => null,
            'uacs_object_code_id' => null,
            'estimated_useful_life' => null,
            'description' => null,
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
                ->extraAttributes(['class' => 'owwa-bulk-items-repeater owwa-line-table'])
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
                TableColumn::make('Item family')->markAsRequired()->width('18%'),
                TableColumn::make('Variant')->width('12%'),
                TableColumn::make('Unit')->markAsRequired()->width('8%'),
                TableColumn::make('Reorder Point')->markAsRequired()->width('6%'),
                TableColumn::make('Inventory Type')->markAsRequired()->width('20%'),
                TableColumn::make('Days To Consume')->width('8%'),
                TableColumn::make('Description')->width('23%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                TextInput::make('reorder_level')
                    ->hiddenLabel()
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                self::lockedChoice(
                    Select::make('inventory_type')
                        ->hiddenLabel()
                        ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                            ItemAttributeOption::KIND_INVENTORY_TYPE,
                            $get('inventory_type'),
                        )),
                ),
                TextInput::make('days_to_consume')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0),
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
                TableColumn::make('Item family')->markAsRequired()->width('14%'),
                TableColumn::make('Variant')->width('9%'),
                TableColumn::make('Unit')->markAsRequired()->width('8%'),
                TableColumn::make('Property Class')->markAsRequired()->width('16%'),
                TableColumn::make('UACS Object Code')->width('22%'),
                TableColumn::make('Estimated Useful Life')->markAsRequired()->width('9%'),
                TableColumn::make('Description')->width('15%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                self::lockedChoice(
                    Select::make('property_class')
                        ->hiddenLabel()
                        ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                            ItemAttributeOption::KIND_PROPERTY_CLASS,
                            $get('property_class'),
                        ))
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(function (mixed $state): ?string {
                            if (blank($state)) {
                                return null;
                            }

                            return ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PROPERTY_CLASS,
                                $state,
                            ) ?? trim((string) $state);
                        })
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if (blank($state)) {
                                return;
                            }

                            $resolved = ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PROPERTY_CLASS,
                                $state,
                            );
                            if ($resolved !== null && $resolved !== $state) {
                                $set('property_class', $resolved);
                            }
                        }),
                ),
                Select::make('uacs_object_code_id')
                    ->hiddenLabel()
                    ->options(fn (): array => self::uacsOptions())
                    ->searchable(),
                TextInput::make('estimated_useful_life')
                    ->hiddenLabel()
                    ->placeholder('Months, e.g. 36'),
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
                TableColumn::make('Item family')->markAsRequired()->width('16%'),
                TableColumn::make('Variant')->width('11%'),
                TableColumn::make('Unit')->markAsRequired()->width('8%'),
                TableColumn::make('Type of PPE')->markAsRequired()->width('20%'),
                TableColumn::make('UACS Object Code')->markAsRequired()->width('22%'),
                TableColumn::make('Description')->width('17%'),
            ],
            [
                ...self::commonLeadingFields($categoryId),
                self::lockedChoice(
                    Select::make('ppe_type')
                        ->hiddenLabel()
                        ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                            ItemAttributeOption::KIND_PPE_TYPE,
                            $get('ppe_type'),
                        ))
                        ->dehydrateStateUsing(function (mixed $state): ?string {
                            if (blank($state)) {
                                return null;
                            }

                            return ItemAttributeOption::resolveStoredValue(
                                ItemAttributeOption::KIND_PPE_TYPE,
                                $state,
                            ) ?? trim((string) $state);
                        }),
                ),
                Select::make('uacs_object_code_id')
                    ->hiddenLabel()
                    ->options(fn (): array => self::uacsOptions())
                    ->searchable(),
                TextInput::make('description')
                    ->hiddenLabel()
                    ->maxLength(500),
            ],
        ];
    }

    protected static function lockedChoice(Select $select): Select
    {
        return $select
            ->native(false)
            ->selectablePlaceholder(false)
            ->placeholder('Select an option')
            ->extraAttributes(['class' => 'owwa-locked-choice']);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function commonLeadingFields(int $categoryId): array
    {
        return [
            TextInput::make('base_name')
                ->hiddenLabel()
                ->maxLength(255)
                ->datalist(fn (): array => Item::familySuggestionsForCategory($categoryId)),
            TextInput::make('sub_item')
                ->hiddenLabel()
                ->maxLength(255),
            self::lockedChoice(
                Select::make('unit')
                    ->hiddenLabel()
                    ->searchable()
                    ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                        ItemAttributeOption::KIND_UNIT,
                        $get('unit'),
                    )),
            ),
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
}
