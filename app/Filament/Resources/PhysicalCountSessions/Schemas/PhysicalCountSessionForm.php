<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\Pages\CreatePhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\EditPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Models\Item;
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
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->searchable()
                            ->live()
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

                                return 'Assigned automatically from counted items after you add or load stock lines.';
                            })
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->columnSpanFull(),
                        Select::make('ppe_type')
                            ->label('Type of PPE')
                            ->options(PpePropertyType::options())
                            ->searchable()
                            ->live()
                            ->helperText('Scopes RPCPPE expected assets and prints as Type of PPE on Appendix 73.')
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $set('inventory_type_label', PpePropertyType::propertyTypeLabel($state));
                            })
                            ->columnSpanFull(),
                        TextInput::make('accountable_officer_name')
                            ->label('Accountable officer')
                            ->maxLength(255)
                            ->datalist(fn (Get $get): array => self::officerNameSuggestions(
                                filled($get('office_id')) ? (int) $get('office_id') : null,
                                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                            )),
                        TextInput::make('accountable_officer_designation')
                            ->label('Designation')
                            ->maxLength(255)
                            ->datalist(fn (Get $get): array => self::designationSuggestions(
                                filled($get('office_id')) ? (int) $get('office_id') : null,
                            )),
                        DatePicker::make('date_of_assumption')
                            ->label('Date of assumption'),
                    ]),
                Section::make('Signatories')
                    ->description(fn (Get $get): string => match ($get('count_type')) {
                        PhysicalCountSession::TYPE_RPCPPE => 'Appendix 73 RPCPPE — Certified by (D39), Approved by (G39), Verified by (K39).',
                        PhysicalCountSession::TYPE_RPCSP => 'Annex A.8 RPCSP — Certified by (C39), Approved by (F39), Verified by (J39) on property-class sheets.',
                        default => 'Appendix 66 RPCI — Certified by (C39), Approved by (G39), Verified by (K39).',
                    })
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('certified_by_printed_name')
                            ->label('Certified by')
                            ->maxLength(255)
                            ->datalist(fn (Get $get): array => self::officerNameSuggestions(
                                filled($get('office_id')) ? (int) $get('office_id') : null,
                                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
                            )),
                        TextInput::make('approved_by_printed_name')
                            ->label('Approved by')
                            ->maxLength(255)
                            ->datalist(fn (Get $get): array => self::officerNameSuggestions(
                                filled($get('office_id')) ? (int) $get('office_id') : null,
                                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED,
                            )),
                        TextInput::make('verified_by_printed_name')
                            ->label('Verified by')
                            ->maxLength(255)
                            ->datalist(fn (Get $get): array => self::officerNameSuggestions(
                                filled($get('office_id')) ? (int) $get('office_id') : null,
                                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED,
                            )),
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
                        PhysicalCountSession::TYPE_RPCI => 'Use Load stock lines after saving, then enter On hand per count manually for each item.',
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
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('12rem; min-width: 12rem'),
                                TableColumn::make('Description')
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('10rem; min-width: 8rem'),
                                TableColumn::make(self::countLineIdentifierLabel($get('count_type')))
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('8rem; min-width: 8rem'),
                                TableColumn::make('Unit')
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('6.5rem; min-width: 6.5rem'),
                                TableColumn::make('Balance per card')
                                    ->wrapHeader()
                                    ->alignment(Alignment::End)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('6.5rem; min-width: 6.5rem'),
                                TableColumn::make('On hand per count')
                                    ->markAsRequired()
                                    ->wrapHeader()
                                    ->alignment(Alignment::End)
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('6.5rem; min-width: 6.5rem'),
                                TableColumn::make('Remarks')
                                    ->verticalAlignment(VerticalAlignment::Center)
                                    ->width('8rem; min-width: 7rem'),
                            ])
                            ->schema(fn (Get $get): array => self::countLineSchema($get('count_type')))
                            ->defaultItems(0)
                            ->minItems(0)
                            ->addActionLabel('Add item line')
                            ->compact()
                            ->extraAttributes([
                                'class' => 'owwa-pc-count-lines-repeater',
                                'style' => 'overflow-x: auto;',
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
            Select::make('item_id')
                ->label('Article (Item)')
                ->options(function (Get $get): array {
                    $categoryId = $get('../../item_category_id');
                    $countType = $get('../../count_type');
                    $query = Item::query()
                        ->active()
                        ->orderBy('name');
                    if (filled($categoryId)) {
                        $query->where('item_category_id', (int) $categoryId);
                    }

                    if ($countType === PhysicalCountSession::TYPE_RPCPPE && filled($get('../../ppe_type'))) {
                        $query->where('ppe_type', (string) $get('../../ppe_type'));
                    }

                    if ($countType === PhysicalCountSession::TYPE_RPCSP && filled($get('../../property_class'))) {
                        $query->where('property_class', (string) $get('../../property_class'));
                    }

                    return $query->pluck('name', 'id')->all();
                })
                ->searchable()
                ->required()
                ->live()
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
                ->label('Balance per card')
                ->numeric()
                ->default(0),
            TextInput::make('on_hand_count')
                ->label('On hand per count')
                ->numeric()
                ->default(0),
            TextInput::make('remarks')->label('Remarks'),
            Hidden::make('article'),
            $isConsumable
                ? Hidden::make('property_number')
                : Hidden::make('stock_number'),
        ];
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

    /**
     * @return list<string>
     */
    public static function designationSuggestions(?int $officeId): array
    {
        $names = collect(ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION,
        ));

        if ($officeId !== null) {
            $office = Office::query()->find($officeId);
            if ($office) {
                $names = $names->merge([
                    $office->accountable_officer_designation,
                    $office->authorized_officer_designation,
                    $office->supply_custodian_designation,
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
            ],
        );
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
