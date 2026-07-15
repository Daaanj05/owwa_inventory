<?php

namespace App\Filament\Resources\PropertyActionRequests\Schemas;

use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use App\Services\OfficePropertyRegisterService;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\SemiExpendableUsefulLife;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PropertyActionRequestForm
{
    /** @var array<int, Issuance> */
    protected static array $issuanceCache = [];

    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitConsolidator = $user?->isUnitConsolidator() ?? false;

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('reference_code')
                            ->label('Reference No.')
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation !== 'create'),
                        Select::make('office_id')
                            ->label('Office')
                            ->options(function () use ($user): array {
                                if (! $user instanceof User) {
                                    return [];
                                }

                                $officeIds = $user->assignedOfficeIds();

                                if ($officeIds === []) {
                                    return [];
                                }

                                return Office::query()
                                    ->active()
                                    ->whereIn('id', $officeIds)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required(fn (): bool => $isUnitConsolidator)
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->dehydrated()
                            ->live()
                            ->visible(fn (): bool => $isUnitConsolidator)
                            ->afterStateUpdated(function (Set $set, Get $get) use ($user): void {
                                $set('accountable_user_id', null);
                                $set('lines', [
                                    ['issuance_id' => null, 'inventory_unit_id' => null],
                                ]);

                                if (! $user instanceof User) {
                                    $set('department_id', null);

                                    return;
                                }

                                $officeId = self::intOrNull($get('office_id'));

                                if ($officeId === null) {
                                    $set('department_id', null);

                                    return;
                                }

                                $allowedDepartmentIds = $user->assignedDepartmentIdsForOffice($officeId);

                                if ($user->hasSingleDepartmentAssignmentForOffice($officeId)) {
                                    $set('department_id', $allowedDepartmentIds[0] ?? null);

                                    return;
                                }

                                $currentDepartmentId = self::intOrNull($get('department_id'));

                                if ($currentDepartmentId === null || ! in_array($currentDepartmentId, $allowedDepartmentIds, true)) {
                                    $set('department_id', null);
                                }
                            }),
                        Select::make('department_id')
                            ->label('Department')
                            ->options(function (Get $get) use ($user): array {
                                if (! $user instanceof User) {
                                    return [];
                                }

                                $officeId = self::intOrNull($get('office_id'));

                                if ($officeId === null) {
                                    return [];
                                }

                                $departmentIds = $user->assignedDepartmentIdsForOffice($officeId);

                                if ($departmentIds === []) {
                                    return [];
                                }

                                return Department::query()
                                    ->active()
                                    ->where('office_id', $officeId)
                                    ->whereIn('id', $departmentIds)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required(fn (): bool => $isUnitConsolidator)
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->dehydrated()
                            ->live()
                            ->visible(fn (): bool => $isUnitConsolidator)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('accountable_user_id', null);
                                $set('lines', [
                                    ['issuance_id' => null, 'inventory_unit_id' => null],
                                ]);
                            }),
                        Select::make('accountable_user_id')
                            ->label('Employee')
                            ->options(function (Get $get) use ($user): array {
                                if (! $user instanceof User) {
                                    return [];
                                }

                                return app(EmployeeDistributionInventoryService::class)
                                    ->employeesForOfficeDepartment(
                                        $user,
                                        self::intOrNull($get('office_id')),
                                        self::intOrNull($get('department_id')),
                                    );
                            })
                            ->required(fn (): bool => $isUnitConsolidator)
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->dehydrated()
                            ->live()
                            ->visible(fn (): bool => $isUnitConsolidator)
                            ->disabled(fn (Get $get): bool => blank($get('office_id')) || blank($get('department_id')))
                            ->afterStateUpdated(fn (Set $set): mixed => $set('lines', [
                                ['issuance_id' => null, 'inventory_unit_id' => null],
                            ])),
                        Select::make('item_category_id')
                            ->label('Category')
                            ->options(fn (): array => InventoryCategoryOptions::propertyCategoryOptions())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->afterStateUpdated(fn (callable $set): mixed => $set('lines', [
                                ['issuance_id' => null, 'inventory_unit_id' => null],
                            ])),
                        Select::make('action_type')
                            ->label('Action Type')
                            ->options([
                                PropertyActionRequest::ACTION_RETURN => 'Return',
                                PropertyActionRequest::ACTION_REPLACEMENT => 'Replacement',
                                PropertyActionRequest::ACTION_DISPOSAL => 'Disposal',
                            ])
                            ->required()
                            ->live()
                            ->disabled(fn (?PropertyActionRequest $record): bool => $record !== null && $record->status !== PropertyActionRequest::STATUS_DRAFT),
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options(fn (Get $get): array => config('property_action_reasons.'.$get('action_type'), []))
                            ->markAsRequired()
                            ->searchable()
                            ->visible(fn (Get $get): bool => filled($get('action_type'))),
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->label('Items')
                            ->hiddenLabel()
                            ->table(fn (Get $get): array => [
                                TableColumn::make('Item')
                                    ->markAsRequired()
                                    ->alignment(Alignment::Start)
                                    ->width('32%'),
                                TableColumn::make('Qty')
                                    ->alignment(Alignment::End)
                                    ->width('4rem'),
                                TableColumn::make(self::assetIdentifierColumnLabel(
                                    self::intOrNull($get('item_category_id')),
                                ))
                                    ->alignment(Alignment::Start)
                                    ->width('13rem'),
                                TableColumn::make('Issued')
                                    ->alignment(Alignment::End)
                                    ->width('9rem'),
                            ])
                            ->compact()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'owwa-property-action-lines-repeater'])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add Item')
                            ->reorderable(false)
                            ->deleteAction(self::repeaterDeleteVisibleExceptFirst())
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->schema([
                                Select::make('issuance_id')
                                    ->label('Item')
                                    ->hiddenLabel()
                                    ->options(fn (Get $get): array => self::issuanceOptions(
                                        $user,
                                        self::intOrNull($get('../../item_category_id')),
                                        self::intOrNull($get('../../accountable_user_id')),
                                        self::intOrNull($get('../../office_id')),
                                        self::intOrNull($get('../../department_id')),
                                    ))
                                    ->placeholder(function (Get $get) use ($isUnitConsolidator): string {
                                        if ($isUnitConsolidator && blank($get('../../accountable_user_id'))) {
                                            return 'Select an employee first';
                                        }

                                        if (blank($get('../../item_category_id'))) {
                                            return 'Select a category first';
                                        }

                                        return 'Select an item';
                                    })
                                    ->disabled(function (Get $get) use ($isUnitConsolidator): bool {
                                        if (blank($get('../../item_category_id'))) {
                                            return true;
                                        }

                                        return $isUnitConsolidator && blank($get('../../accountable_user_id'));
                                    })
                                    ->required()
                                    ->searchable()
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state) {
                                            $set('inventory_unit_id', null);

                                            return;
                                        }

                                        $issuance = self::resolveIssuance((int) $state);

                                        if (! $issuance) {
                                            return;
                                        }

                                        $set('inventory_unit_id', $issuance->inventoryUnit?->id);
                                    }),
                                Placeholder::make('quantity_display')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (Get $get): string => self::issuanceQuantityLabel(
                                        self::intOrNull($get('issuance_id')),
                                    )),
                                Placeholder::make('asset_identifier_display')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (Get $get): string => self::issuanceIdentifierLabel(
                                        self::intOrNull($get('issuance_id')),
                                    )),
                                Placeholder::make('issued_date_display')
                                    ->label('Issued')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (Get $get): string => self::issuanceDateLabel(
                                        self::intOrNull($get('issuance_id')),
                                    )),
                                Hidden::make('inventory_unit_id'),
                            ])
                            ->rules([
                                function (Get $get) use ($isUnitConsolidator): \Closure {
                                    return function (string $attribute, mixed $value, \Closure $fail) use ($get, $isUnitConsolidator): void {
                                        if ($isUnitConsolidator && blank($get('accountable_user_id'))) {
                                            $fail('Select an employee before choosing properties.');

                                            return;
                                        }

                                        if (blank($get('item_category_id'))) {
                                            $fail('Select a category before choosing properties.');

                                            return;
                                        }

                                        $lines = is_array($value) ? $value : [];
                                        $issuanceIds = collect($lines)
                                            ->pluck('issuance_id')
                                            ->filter()
                                            ->map(fn (mixed $id): int => (int) $id)
                                            ->all();

                                        if (count($issuanceIds) !== count(array_unique($issuanceIds))) {
                                            $fail('Each property may only be added once.');
                                        }
                                    };
                                },
                            ]),
                        ...(! $isUnitConsolidator ? [
                            Hidden::make('office_id'),
                            Hidden::make('department_id'),
                            Hidden::make('accountable_user_id'),
                        ] : []),
                        Hidden::make('requested_by')
                            ->default(fn (): ?int => $user?->id),
                        Textarea::make('reason_detail')
                            ->label('Details')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function issuanceOptions(
        ?User $user,
        ?int $categoryId = null,
        ?int $employeeId = null,
        ?int $officeId = null,
        ?int $departmentId = null,
    ): array {
        if (! $user instanceof User) {
            return [];
        }

        if ($categoryId === null || $categoryId <= 0) {
            return [];
        }

        if ($user->isEmployee()) {
            $query = Issuance::query()
                ->with(['item.category', 'inventoryUnit'])
                ->where('issued_to', $user->id)
                ->whereHas('item.category', fn ($q) => $q->whereIn('name', app(OfficePropertyRegisterService::class)->propertyCategoryNames()))
                ->whereHas('item', fn ($q) => $q->where('item_category_id', $categoryId));
        } elseif ($user->isUnitConsolidator()) {
            if ($employeeId === null || $employeeId <= 0) {
                return [];
            }

            $query = Issuance::query()
                ->with(['item.category', 'inventoryUnit'])
                ->where('issued_to', $employeeId)
                ->whereNull('custody_ended_at')
                ->where(function (Builder $scope): void {
                    $scope
                        ->whereDoesntHave('inventoryUnit')
                        ->orWhereHas(
                            'inventoryUnit',
                            fn (Builder $unitQuery): Builder => $unitQuery->where('status', InventoryUnit::STATUS_ISSUED),
                        );
                })
                ->whereHas('item.category', fn ($q) => $q->whereIn('name', app(OfficePropertyRegisterService::class)->propertyCategoryNames()))
                ->whereHas('item', fn ($q) => $q->where('item_category_id', $categoryId));

            if ($officeId !== null && $officeId > 0) {
                $query->where('office_id', $officeId);
            }

            if ($departmentId !== null && $departmentId > 0) {
                $query->where('department_id', $departmentId);
            }
        } else {
            return [];
        }

        if ($user->isUnitConsolidator()) {
            $issuances = $query
                ->orderByRaw('case when eul_expires_at is null then 1 else 0 end')
                ->orderBy('eul_expires_at')
                ->orderByDesc('issuance_date')
                ->limit(200)
                ->get();
        } else {
            $issuances = $query
                ->latest('issuance_date')
                ->limit(200)
                ->get();
        }

        foreach ($issuances as $issuance) {
            self::$issuanceCache[$issuance->id] = $issuance;
        }

        $nameCounts = $issuances
            ->groupBy(fn (Issuance $issuance): string => $issuance->item?->name ?? '')
            ->map(fn ($group): int => $group->count());

        return $issuances
            ->mapWithKeys(function (Issuance $issuance) use ($nameCounts, $user): array {
                $name = $issuance->item?->name ?? 'Unknown item';
                $label = $name;

                $identifier = $issuance->property_number ?? $issuance->reference_code ?? '';

                if ($identifier !== '' && (($nameCounts[$name] ?? 0) > 1 || ($user?->isUnitConsolidator() ?? false))) {
                    $suffix = strlen($identifier) > 12
                        ? '…'.substr($identifier, -6)
                        : $identifier;
                    $label = "{$name} — {$suffix}";
                }

                if ($user?->isUnitConsolidator() ?? false) {
                    $eulStatus = SemiExpendableUsefulLife::statusForIssuance($issuance);
                    $eulLabel = SemiExpendableUsefulLife::statusLabel($eulStatus);

                    if ($eulLabel !== '—') {
                        $label .= " ({$eulLabel})";
                    } elseif ($issuance->eul_expires_at !== null) {
                        $label .= ' (EUL '.$issuance->eul_expires_at->format('M d, Y').')';
                    }
                }

                return [$issuance->id => $label];
            })
            ->all();
    }

    public static function assetIdentifierColumnLabel(?int $categoryId): string
    {
        if ($categoryId === null || $categoryId <= 0) {
            return OwwaReferenceLabels::PROPERTY_NO;
        }

        $category = ItemCategory::query()->find($categoryId);

        if (! $category instanceof ItemCategory) {
            return OwwaReferenceLabels::PROPERTY_NO;
        }

        return OwwaReferenceLabels::assetIdentifierLabel($category->getTemplateSlug());
    }

    public static function resolveIssuance(?int $issuanceId): ?Issuance
    {
        if ($issuanceId === null || $issuanceId <= 0) {
            return null;
        }

        if (isset(self::$issuanceCache[$issuanceId])) {
            return self::$issuanceCache[$issuanceId];
        }

        $issuance = Issuance::query()
            ->with(['item', 'inventoryUnit'])
            ->find($issuanceId);

        if ($issuance) {
            self::$issuanceCache[$issuanceId] = $issuance;
        }

        return $issuance;
    }

    public static function issuanceIdentifierLabel(?int $issuanceId): string
    {
        $issuance = self::resolveIssuance($issuanceId);

        if (! $issuance) {
            return '—';
        }

        return $issuance->property_number
            ?? $issuance->reference_code
            ?? '—';
    }

    public static function issuanceDateLabel(?int $issuanceId): string
    {
        $issuance = self::resolveIssuance($issuanceId);

        if (! $issuance) {
            return '—';
        }

        return $issuance->issuance_date?->format('M d, Y') ?? '—';
    }

    public static function issuanceQuantityLabel(?int $issuanceId): string
    {
        $issuance = self::resolveIssuance($issuanceId);

        if (! $issuance) {
            return '—';
        }

        $quantity = $issuance->quantity;

        if ($quantity === null) {
            return '—';
        }

        return (string) (int) $quantity;
    }

    protected static function repeaterDeleteVisibleExceptFirst(): Closure
    {
        return fn (Action $action): Action => $action->visible(function (array $arguments, Repeater $component): bool {
            if (! $component->isDeletable()) {
                return false;
            }

            $itemKeys = array_keys($component->getRawState() ?? []);
            $index = array_search($arguments['item'] ?? null, $itemKeys, true);

            return $index !== false && $index > 0;
        });
    }

    public static function intOrNull(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateParentFromLines(array $data, ?User $user): array
    {
        $data['status'] = PropertyActionRequest::STATUS_DRAFT;

        $lines = $data['lines'] ?? [];
        $firstIssuanceId = collect(is_array($lines) ? $lines : [])
            ->pluck('issuance_id')
            ->filter()
            ->first();

        if ($firstIssuanceId) {
            $issuance = Issuance::query()->find((int) $firstIssuanceId);

            if ($issuance) {
                $data['office_id'] = $data['office_id'] ?? $issuance->office_id;
                $data['department_id'] = $data['department_id'] ?? $issuance->department_id;
                $data['accountable_user_id'] = $data['accountable_user_id']
                    ?? $issuance->issued_to
                    ?? $user?->id;
            }
        }

        if ($user instanceof User && $user->isUnitConsolidator()) {
            if (empty($data['accountable_user_id'])) {
                throw ValidationException::withMessages([
                    'accountable_user_id' => 'Select the employee accountable for this property return.',
                ]);
            }

            if (empty($data['office_id']) || empty($data['department_id'])) {
                throw ValidationException::withMessages([
                    'office_id' => 'Select the office and department for this property return.',
                ]);
            }
        }

        if ($user instanceof User && empty($data['office_id']) && $user->office_id) {
            $data['office_id'] = $user->office_id;
            $data['department_id'] = $user->department_id;
            $data['accountable_user_id'] = $data['accountable_user_id'] ?? $user->id;
        }

        if ($user instanceof User && empty($data['requested_by'])) {
            $data['requested_by'] = $user->id;
        }

        return $data;
    }
}
