<?php

namespace App\Filament\Resources\IncidentReports\Schemas;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Services\DisposalInventoryUnitService;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaReferenceLabels;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IncidentReportForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();
        $unitService = app(DisposalInventoryUnitService::class);

        $syncItemOffice = function (Get $get, Set $set) use ($unitService): void {
            $set('department_id', null);

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
                    ->default('lost_stolen_damaged')
                    ->dehydrated(),

                Hidden::make('inventory_auto_synced')
                    ->dehydrated(false)
                    ->default(false),

                Section::make('Incident report')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(fn (): string => OwwaReferenceLabels::incidentReport())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        DatePicker::make('disposal_date')
                            ->label('Report date')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make('Property involved')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => self::incidentCategoryOptions())
                            ->placeholder('All property categories')
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
                                    $query->whereIn('item_category_id', self::incidentCategoryIds());

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
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship(
                                'department',
                                'name',
                                fn (Builder $query, Get $get) => $query
                                    ->active()
                                    ->when(
                                        filled($get('office_id')),
                                        fn (Builder $scoped): Builder => $scoped->where('office_id', $get('office_id')),
                                    ),
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('—')
                            ->helperText('Maps to RLSDDP Department/Office.')
                            ->visible(fn (Get $get): bool => filled($get('office_id')))
                            ->required(fn (Get $get): bool => self::itemCategorySlug($get) === 'semi_expendable'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->disabled(fn (Get $get): bool => filled($get('inventory_unit_id')))
                            ->dehydrated()
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (filled($get('inventory_unit_id')) && (int) $value !== 1) {
                                        $fail('Quantity must be 1 when a specific inventory unit is selected.');
                                    }
                                },
                            ]),
                        TextInput::make('reason')
                            ->label('Summary')
                            ->placeholder('Brief summary of the incident')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Asset details')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => filled($get('item_id')))
                    ->schema([
                        Select::make('inventory_unit_id')
                            ->label(fn (Get $get): string => 'Specific '.OwwaReferenceLabels::assetIdentifierLabel(
                                self::itemCategorySlug($get)
                            ))
                            ->options(fn (Get $get): array => $unitService->unitOptions(
                                self::intOrNull($get('item_id')),
                                self::intOrNull($get('office_id')),
                            ))
                            ->searchable()
                            ->live()
                            ->visible(fn (Get $get): bool => $unitService->hasAvailableUnits(
                                self::intOrNull($get('item_id')),
                                self::intOrNull($get('office_id')),
                            ) && $unitService->availableUnitsQuery(
                                self::intOrNull($get('item_id')),
                                self::intOrNull($get('office_id')),
                            )->count() > 1)
                            ->required(fn (Get $get): bool => self::requiresInventoryUnit($get))
                            ->helperText('Select the exact physical unit involved in the incident.')
                            ->columnSpanFull()
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $unit = InventoryUnit::query()->find($value);
                                    if ($unit?->status === InventoryUnit::STATUS_DISPOSED) {
                                        $fail('This inventory unit has already been disposed.');
                                    }
                                },
                            ])
                            ->afterStateUpdated(function ($state, Set $set, Get $get) use ($unitService, $syncItemOffice): void {
                                if (blank($state)) {
                                    $syncItemOffice($get, $set);

                                    return;
                                }

                                $unit = InventoryUnit::query()
                                    ->with(['issuance', 'acquisition'])
                                    ->find($state);

                                if ($unit === null) {
                                    return;
                                }

                                $unitService->applyUnitToFormState($unit, $set);
                            }),
                        TextInput::make('property_number')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::assetIdentifierLabel(
                                self::itemCategorySlug($get)
                            ))
                            ->maxLength(255)
                            ->required()
                            ->disabled(fn (Get $get): bool => filled($get('inventory_unit_id')))
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => filled($get('inventory_unit_id'))
                                ? 'Auto-filled from inventory.'
                                : 'Enter the inventory item or property number.'),
                        TextInput::make('acquisition_cost')
                            ->label('Acquisition cost')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->required()
                            ->disabled(fn (Get $get): bool => (bool) $get('inventory_auto_synced') || filled($get('inventory_unit_id')))
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => (bool) $get('inventory_auto_synced') || filled($get('inventory_unit_id'))
                                ? 'Auto-filled from inventory.'
                                : 'Enter acquisition cost if not available from records.')
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if ($value === null || $value === '' || (float) $value <= 0) {
                                        $fail('Acquisition cost is required for this incident report.');
                                    }
                                },
                            ]),
                        TextInput::make('par_issuance_display')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::issuanceControl(
                                self::itemCategorySlug($get)
                            ))
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('par_issuance_id'))
                                && filled($get('inventory_unit_id')))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                $issuanceId = $get('par_issuance_id');
                                if (blank($issuanceId)) {
                                    return;
                                }

                                $reference = Issuance::query()->whereKey($issuanceId)->value('reference_code');
                                $component->state(filled($reference) ? $reference : '—');
                            }),
                        Select::make('par_issuance_id')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::issuanceControl(
                                self::itemCategorySlug($get)
                            ))
                            ->options(fn (Get $get): array => Issuance::query()
                                ->when(filled($get('item_id')), fn (Builder $query) => $query->where('item_id', $get('item_id')))
                                ->when(filled($get('office_id')), fn (Builder $query) => $query->where('office_id', $get('office_id')))
                                ->orderByDesc('issuance_date')
                                ->limit(200)
                                ->pluck('reference_code', 'id')
                                ->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => blank($get('inventory_unit_id')))
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (blank($state)) {
                                    $set('department_id', null);

                                    return;
                                }

                                $departmentId = Issuance::query()->whereKey($state)->value('department_id');
                                $set('department_id', $departmentId);
                            }),
                    ])
                    ->columns(2),

                Section::make('Incident details')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('property_status')
                            ->label('Property status')
                            ->options([
                                'lost' => 'Lost',
                                'stolen' => 'Stolen',
                                'damaged' => 'Damaged',
                                'destroyed' => 'Destroyed',
                            ])
                            ->required(),
                        Textarea::make('circumstances')
                            ->label('Circumstances')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        TextInput::make('accountable_officer_designation')
                            ->label('Accountable officer designation')
                            ->maxLength(255),
                        TextInput::make('accountable_officer_station')
                            ->label('Accountable officer station / office')
                            ->maxLength(255),
                        Toggle::make('police_notified')
                            ->label('Police notified')
                            ->live(),
                        TextInput::make('police_station')
                            ->label('Police station')
                            ->visible(fn (Get $get): bool => (bool) $get('police_notified')),
                        DatePicker::make('police_notified_date')
                            ->label('Police notification date')
                            ->visible(fn (Get $get): bool => (bool) $get('police_notified')),
                        TextInput::make('gov_id_type')
                            ->label('Government ID type'),
                        TextInput::make('gov_id_no')
                            ->label('ID number'),
                        DatePicker::make('gov_id_date_issued')
                            ->label('ID date issued'),
                        TextInput::make('immediate_supervisor_printed_name')
                            ->label('Immediate supervisor (Noted by)'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Signatories')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('custodian_printed_name')
                            ->label('Accountable officer')
                            ->maxLength(255)
                            ->placeholder('Full name')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function incidentCategoryIds(): array
    {
        return ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => in_array(
                $category->getTemplateSlug(),
                ['ppe', 'semi_expendable'],
                true,
            ))
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function incidentCategoryOptions(): array
    {
        return ItemCategory::query()
            ->whereIn('id', self::incidentCategoryIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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

    protected static function requiresInventoryUnit(Get $get): bool
    {
        $itemId = self::intOrNull($get('item_id'));
        $officeId = self::intOrNull($get('office_id'));
        $service = app(DisposalInventoryUnitService::class);

        return $service->availableUnitsQuery($itemId, $officeId)->count() > 1;
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
}
