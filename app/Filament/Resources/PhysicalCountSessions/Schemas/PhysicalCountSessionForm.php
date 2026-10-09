<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\Pages\CreatePhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\EditPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\ProcurementSignatoryName;
use App\Services\InventoryStockService;
use App\Support\ConsumableInventoryType;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use App\Support\OwwaReferenceLabels;
use App\Support\PhysicalCountPropertyClassResolver;
use App\Support\PhysicalCountSessionViewPresenter;
use App\Support\PpePropertyType;
use App\Support\SignatorySelect;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;

class PhysicalCountSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Count session')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Hidden::make('count_type')
                            ->default(fn (Get $get): string => self::resolveCountTypeForCategoryId(
                                $get('item_category_id') ?: SyncsActiveItemCategory::resolveCategoryIdFromContext(),
                            ))
                            ->dehydrated()
                            ->live(),
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
                            ->disabled(fn (): bool => CustodianOfficeScope::hasFixedInventoryOffice())
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $defaults = OfficeSignatoryDefaults::forPhysicalCountSession((int) $state);
                                foreach ($defaults as $field => $value) {
                                    if (filled($value)) {
                                        $set($field, $value);
                                    }
                                }
                            }),
                        Select::make('item_category_id')
                            ->label('Item category')
                            ->options(fn (): array => ItemCategory::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): mixed => SyncsActiveItemCategory::resolveCategoryIdFromContext())
                            ->live()
                            ->required(fn (): bool => ! self::isCategoryScoped())
                            ->visible(fn (): bool => ! self::isCategoryScoped())
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('count_type', self::resolveCountTypeForCategoryId((int) $state));
                            }),
                        DatePicker::make('count_date')
                            ->label('As at date')
                            ->required()
                            ->default(now()),
                        Hidden::make('inventory_type')
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI),
                        Hidden::make('inventory_type_label')
                            ->dehydrated(),
                        Placeholder::make('inventory_type_resolved')
                            ->label('Inventory type')
                            ->content(function (Get $get, $record): string {
                                if ($record instanceof PhysicalCountSession) {
                                    return PhysicalCountPropertyClassResolver::displayInventoryTypeText($record);
                                }

                                return 'Assigned automatically from counted items after you add or Load Items.';
                            })
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->columnSpanFull(),
                        Toggle::make('load_items_on_create')
                            ->label('Load Items')
                            ->helperText(self::loadItemsHelperText())
                            ->default(true)
                            ->inline(false)
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                                && $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->columnSpanFull(),
                        Select::make('ppe_type')
                            ->label('Type of PPE')
                            ->options(fn (Get $get): array => ItemAttributeOption::optionsForKindIncluding(
                                ItemAttributeOption::KIND_PPE_TYPE,
                                $get('ppe_type'),
                            ))
                            ->live()
                            ->required(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->helperText('Scopes RPCPPE expected assets and prints as Type of PPE on Appendix 73.')
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $set('inventory_type_label', PpePropertyType::propertyTypeLabel($state));
                            })
                            ->columnSpanFull(),
                        SignatorySelect::makeFromSuggestions('accountable_officer_name', ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE, fn (Get $get): array => self::officerNameSuggestions(
                            filled($get('office_id')) ? (int) $get('office_id') : null,
                            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                        ))
                            ->label('Accountable Officer')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('accountable_officer_designation', ProcurementSignatoryName::designationFor(
                                    ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                                    is_string($state) ? $state : null,
                                ));
                            }),
                        SignatorySelect::makeDesignation('accountable_officer_designation', ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE)
                            ->label('Designation')
                            ->required(),
                        DatePicker::make('date_of_assumption')
                            ->label('Date of assumption')
                            ->required(),
                    ]),
                Section::make('Signatories')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        SignatorySelect::makeFromSuggestions('certified_by_printed_name', ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED, fn (Get $get): array => self::officerNameSuggestions(
                            filled($get('office_id')) ? (int) $get('office_id') : null,
                            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
                        ))
                            ->label('Certified Correct by')
                            ->required(),
                        SignatorySelect::makeFromSuggestions('approved_by_printed_name', ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED, fn (Get $get): array => self::officerNameSuggestions(
                            filled($get('office_id')) ? (int) $get('office_id') : null,
                            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED,
                        ))
                            ->label('Approved by')
                            ->required(),
                        SignatorySelect::makeFromSuggestions('verified_by_printed_name', ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED, fn (Get $get): array => self::officerNameSuggestions(
                            filled($get('office_id')) ? (int) $get('office_id') : null,
                            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED,
                        ))
                            ->label('Verified by')
                            ->required(),
                    ]),
                Section::make('QR counting workflow')
                    ->description('Property-tag scanning (PPE and semi-expendable)')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('count_type'), [
                        PhysicalCountSession::TYPE_RPCPPE,
                        PhysicalCountSession::TYPE_RPCSP,
                    ], true))
                    ->schema([
                        Placeholder::make('qr_workflow_steps')
                            ->hiddenLabel()
                            ->content(fn (): \Illuminate\Support\HtmlString => PhysicalCountSessionViewPresenter::qrWorkflowStepsHtml())
                            ->columnSpanFull(),
                    ]),
                Section::make('Count lines')
                    ->description(fn (Get $get): ?string => match ($get('count_type')) {
                        PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP => 'Shown on edit only for corrections. On create, use Load expected assets after saving.',
                        PhysicalCountSession::TYPE_RPCI => 'Prefer Load Items, or Add item line. Enter On hand for each item.',
                        default => null,
                    })
                    ->columnSpanFull()
                    ->visible(fn (Get $get, $livewire): bool => self::shouldShowCountLines($get, $livewire))
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->label('Items counted')
                            ->table(fn (Get $get): array => [
                                TableColumn::make('Article (Item)')
                                    ->markAsRequired()
                                    ->alignment(Alignment::Start)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('20%'),
                                TableColumn::make('Description')
                                    ->alignment(Alignment::Start)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('12%'),
                                TableColumn::make(self::countLineIdentifierLabel($get('count_type')))
                                    ->alignment(Alignment::Start)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('18%'),
                                TableColumn::make('Unit')
                                    ->alignment(Alignment::Start)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('10%'),
                                TableColumn::make('Balance')
                                    ->wrapHeader()
                                    ->alignment(Alignment::Center)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('10%'),
                                TableColumn::make('On hand')
                                    ->markAsRequired()
                                    ->wrapHeader()
                                    ->alignment(Alignment::Center)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('10%'),
                                TableColumn::make('Remarks')
                                    ->alignment(Alignment::Start)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('15%'),
                            ])
                            ->schema(fn (Get $get): array => self::countLineSchema($get('count_type')))
                            ->defaultItems(0)
                            ->minItems(0)
                            ->addActionLabel('Add item line')
                            ->compact()
                            ->extraAttributes([
                                'class' => 'owwa-pc-count-lines-repeater owwa-line-table',
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    protected static function countLineSchema(?string $countType): array
    {
        $isConsumable = $countType === PhysicalCountSession::TYPE_RPCI;

        return [
            Group::make([
                Select::make('item_id')
                    ->label('Article (Item)')
                    ->hiddenLabel()
                    ->searchable()
                    ->optionsLimit(30)
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::searchCountLineItems($search, $get))
                    ->getOptionLabelUsing(fn ($value): ?string => Item::query()->whereKey($value)->value('name'))
                    ->required(fn (Get $get): bool => blank($get('item_id')))
                    ->live()
                    ->visible(fn (Get $get): bool => blank($get('item_id')))
                    ->dehydrated()
                    ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                        if (blank($state)) {
                            return;
                        }
                        $item = Item::query()->find($state);
                        if (! $item) {
                            return;
                        }
                        $officeId = $get('../../office_id');
                        $set('article', $item->name);
                        $set('description', $item->description);
                        $set('stock_number', $item->item_code);
                        $set('unit_of_measure', $item->unit);
                        if ($get('../../count_type') === PhysicalCountSession::TYPE_RPCI
                            && filled($item->inventory_type)
                            && blank($get('../../inventory_type'))) {
                            $set('../../inventory_type', $item->inventory_type);
                            $set('../../inventory_type_label', ConsumableInventoryType::label($item->inventory_type));
                        }
                        if ($officeId) {
                            $stock = app(InventoryStockService::class)->getStock((int) $item->id, (int) $officeId);
                            $set('balance_per_card', max(0, $stock));
                            $set('on_hand_count', 0);
                        }
                    }),
                TextInput::make('article')
                    ->label('Article (Item)')
                    ->hiddenLabel()
                    ->disabled()
                    ->dehydrated()
                    ->visible(fn (Get $get): bool => filled($get('item_id'))),
            ])
                ->extraAttributes(['class' => 'owwa-pc-article-cell']),
            TextInput::make('description')->label('Description'),
            $isConsumable
                ? TextInput::make('stock_number')
                    ->label(OwwaReferenceLabels::STOCK_NO)
                    ->disabled()
                    ->dehydrated()
                : TextInput::make('property_number')
                    ->label(self::countLineIdentifierLabel($countType))
                    ->disabled()
                    ->dehydrated(),
            TextInput::make('unit_of_measure')
                ->label('Unit')
                ->disabled()
                ->dehydrated(),
            TextInput::make('balance_per_card')
                ->label('Balance')
                ->numeric()
                ->default(0)
                ->disabled()
                ->dehydrated()
                ->extraInputAttributes(['class' => 'owwa-pc-qty-input']),
            TextInput::make('on_hand_count')
                ->label('On hand')
                ->numeric()
                ->default(0)
                ->extraInputAttributes([
                    'class' => 'owwa-pc-qty-input',
                    'x-on:focus' => '$event.target.select()',
                ]),
            TextInput::make('remarks')->label('Remarks'),
            $isConsumable
                ? Hidden::make('property_number')
                : Hidden::make('stock_number'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected static function searchCountLineItems(string $search, Get $get): array
    {
        $query = Item::query()->active()->orderBy('name');

        $categoryId = $get('../../item_category_id');
        if (filled($categoryId)) {
            $query->where('item_category_id', (int) $categoryId);
        }

        $countType = $get('../../count_type');
        if ($countType === PhysicalCountSession::TYPE_RPCPPE && filled($get('../../ppe_type'))) {
            $query->where('ppe_type', (string) $get('../../ppe_type'));
        }

        if ($countType === PhysicalCountSession::TYPE_RPCSP && filled($get('../../property_class'))) {
            $query->where('property_class', (string) $get('../../property_class'));
        }

        $term = trim($search);
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('item_code', 'like', $like);
            });
        }

        return $query->limit(30)->pluck('name', 'id')->all();
    }

    public static function countLineIdentifierLabel(?string $countType): string
    {
        return match ($countType) {
            PhysicalCountSession::TYPE_RPCPPE => OwwaReferenceLabels::PROPERTY_NO,
            PhysicalCountSession::TYPE_RPCSP => OwwaReferenceLabels::INVENTORY_ITEM_NO,
            default => OwwaReferenceLabels::STOCK_NO,
        };
    }

    /**
     * @return list<string>
     */
    public static function officerNameSuggestions(?int $officeId, string $rememberRole): array
    {
        $names = collect(ProcurementSignatoryName::suggestionsForRole($rememberRole));

        if ($officeId !== null) {
            $office = Office::query()->find($officeId);
            if ($office) {
                $names = $names->merge([
                    $office->accountable_officer_name,
                    $office->authorized_officer_name,
                    $office->supply_custodian_name,
                    $office->inspection_officer_name,
                ]);
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

    public static function resolveCountTypeForCategoryId(int|string|null $categoryId): string
    {
        if (blank($categoryId)) {
            return PhysicalCountSession::TYPE_RPCI;
        }

        $category = ItemCategory::query()->find((int) $categoryId);

        return match ($category?->getTemplateSlug()) {
            'ppe' => PhysicalCountSession::TYPE_RPCPPE,
            'semi_expendable' => PhysicalCountSession::TYPE_RPCSP,
            default => PhysicalCountSession::TYPE_RPCI,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultCreateFormData(?int $categoryId = null): array
    {
        $categoryId ??= SyncsActiveItemCategory::resolveCategoryIdFromContext();
        $officeId = CustodianOfficeScope::inventoryOfficeId();

        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forPhysicalCountSession($officeId),
            [
                'item_category_id' => $categoryId > 0 ? $categoryId : null,
                'count_type' => self::resolveCountTypeForCategoryId($categoryId),
                'count_date' => now()->toDateString(),
                'office_id' => $officeId,
                'load_items_on_create' => self::resolveCountTypeForCategoryId($categoryId) === PhysicalCountSession::TYPE_RPCI,
            ],
        );
    }

    public static function loadItemsHelperText(): string
    {
        return 'Loads items with stock activity for this office and fills Balance per card from the system. On hand per count starts at 0 — enter the physical count yourself.';
    }

    public static function loadItemsCreateModalDescription(): string
    {
        return 'Leave Load Items on to fill item lines from office stock when you create this count. Then enter On hand per count for each item.';
    }

    public static function shouldShowCountLines(Get $get, mixed $livewire): bool
    {
        if (! in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)) {
            return true;
        }

        if ($livewire instanceof EditPhysicalCountSession) {
            return true;
        }

        if ($livewire instanceof CreatePhysicalCountSession) {
            return false;
        }

        if ($livewire instanceof ListPhysicalCountSessions && filled($livewire->mountedActionRecord ?? null)) {
            return true;
        }

        return false;
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (filled(request()->query('category')) || filled(session('active_item_category_id')));
    }
}
