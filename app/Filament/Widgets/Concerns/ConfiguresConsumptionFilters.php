<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\AnalyticsDateRangeService;
use App\Support\CustodianOfficeScope;
use App\Support\InventoryCategoryOptions;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

trait ConfiguresConsumptionFilters
{
    /**
     * Filter form must be fully live (not deferred + partial) so dependent
     * Offices → Departments and Category → Base → Items options refresh on change.
     *
     * Native (non-searchable) selects are required inside the chart filter popover:
     * searchable JS selects keep multiple panels open and often fail to load
     * dynamic Closure options in that nested dropdown context.
     */
    protected function configureConsumptionFiltersSchema(Schema $schema, bool $includeMovingAverage = false): Schema
    {
        return $schema
            ->columns(2)
            ->components($this->consumptionFilterComponents($includeMovingAverage));
    }

    protected function bootConsumptionFilterDefaults(): void
    {
        $this->mountHasFiltersSchema();

        // Native selects with a disabled empty option only stay on "All …"
        // when the bound state is "" (null lets the browser paint the first enabled option).
        $this->filters['item_category_ids'] = $this->normalizeEmptySelectValue($this->filters['item_category_ids'] ?? null);
        $this->filters['base_names'] = $this->normalizeEmptySelectValue($this->filters['base_names'] ?? null);

        if (property_exists($this, 'deferredFilters') && is_array($this->deferredFilters)) {
            $this->deferredFilters['item_category_ids'] = $this->filters['item_category_ids'];
            $this->deferredFilters['base_names'] = $this->filters['base_names'];
        }

        $officeIds = $this->normalizeIds($this->filters['office_ids'] ?? null);
        if ($officeIds !== []) {
            return;
        }

        $defaults = array_map(strval(...), $this->defaultConsumptionOfficeIds());
        if ($defaults === []) {
            return;
        }

        $this->filters['office_ids'] = $defaults;

        if (property_exists($this, 'deferredFilters') && is_array($this->deferredFilters)) {
            $this->deferredFilters['office_ids'] = $defaults;
        }
    }

    protected function normalizeEmptySelectValue(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_array($value)) {
            $first = reset($value);

            return $first === false || $first === null ? '' : (string) $first;
        }

        return (string) $value;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    protected function consumptionFilterComponents(bool $includeMovingAverage = false): array
    {
        $dateRange = app(AnalyticsDateRangeService::class)->currentYearRange();
        $defaultOfficeIds = array_map(strval(...), $this->defaultConsumptionOfficeIds());
        $officeOptions = $this->consumptionOfficeOptions();
        $categoryOptions = InventoryCategoryOptions::procurementAnalyticsCategories()
            ->mapWithKeys(fn (ItemCategory $category): array => [(string) $category->id => (string) $category->name])
            ->all();

        $components = [
            DatePicker::make('date_from')
                ->label('From')
                ->default($dateRange['from']->toDateString())
                ->maxDate(fn (Get $get): mixed => $get('date_to') ?? now()),
            DatePicker::make('date_to')
                ->label('To')
                ->default($dateRange['to']->toDateString())
                ->minDate(fn (Get $get): mixed => $get('date_from') ?? now()->subMonths(11)),
            Select::make('office_ids')
                ->label('Offices')
                ->multiple()
                ->options($officeOptions)
                ->default($defaultOfficeIds)
                ->placeholder('All offices')
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('department_ids', [])),
            Select::make('department_ids')
                ->label('Departments')
                ->multiple()
                ->key(fn (): string => 'department_ids_'.$this->filterOptionsKey('office_ids'))
                ->placeholder(fn (): string => $this->filterIdList('office_ids') !== []
                    ? 'All departments'
                    : 'Select office(s) first')
                ->disabled(fn (): bool => $this->filterIdList('office_ids') === [])
                ->options(fn (): array => $this->departmentOptionsForOffices($this->filterIdList('office_ids')))
                ->getOptionLabelsUsing(fn (array $values): array => Department::query()
                    ->whereIn('id', $values)
                    ->pluck('name', 'id')
                    ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
                    ->all()),
            Select::make('item_category_ids')
                ->label('Category')
                ->options(['' => 'All categories'] + $categoryOptions)
                ->default('')
                ->selectablePlaceholder(false)
                ->disableOptionWhen(fn ($value): bool => blank($value))
                ->native()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('base_names', '');
                    $set('item_ids', '');
                }),
            Select::make('base_names')
                ->label('Base item')
                ->native()
                ->default('')
                ->key(fn (): string => 'base_names_'.$this->filterOptionsKey('item_category_ids'))
                ->selectablePlaceholder(false)
                ->disableOptionWhen(fn ($value): bool => blank($value))
                ->disabled(fn (): bool => $this->filterIdList('item_category_ids') === [])
                ->options(function (): array {
                    $categoryIds = $this->filterIdList('item_category_ids');
                    if ($categoryIds === []) {
                        return ['' => 'Select category first'];
                    }

                    return ['' => 'All base items'] + Item::query()
                        ->active()
                        ->whereIn('item_category_id', $categoryIds)
                        ->orderByRaw('COALESCE(base_name, name)')
                        ->get(['id', 'base_name', 'name'])
                        ->mapWithKeys(function (Item $item): array {
                            $base = filled($item->base_name) ? (string) $item->base_name : (string) $item->name;

                            return [$base => $base];
                        })
                        ->unique()
                        ->sortKeys()
                        ->all();
                })
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('item_ids', '')),
            Select::make('item_ids')
                ->label('Sub-Items')
                ->native()
                ->default('')
                ->columnSpanFull()
                ->key(fn (): string => 'item_ids_'.$this->filterOptionsKey('base_names').'_'.$this->filterOptionsKey('item_category_ids'))
                ->options(function (): array {
                    $categoryIds = $this->filterIdList('item_category_ids');
                    $baseNames = $this->filterStringList('base_names');
                    if ($categoryIds === [] || $baseNames === []) {
                        return ['' => 'Select base item first'];
                    }

                    return ['' => 'All sub-items'] + Item::query()
                        ->active()
                        ->whereIn('item_category_id', $categoryIds)
                        ->where(function ($query) use ($baseNames): void {
                            $query->whereIn('base_name', $baseNames)
                                ->orWhere(function ($inner) use ($baseNames): void {
                                    $inner->whereNull('base_name')->whereIn('name', $baseNames);
                                });
                        })
                        ->orderBy('name')
                        ->get(['id', 'name', 'sub_item'])
                        ->mapWithKeys(fn (Item $item): array => [
                            (string) $item->id => filled($item->sub_item)
                                ? (string) $item->sub_item
                                : (string) $item->name,
                        ])
                        ->all();
                })
                ->selectablePlaceholder(false)
                ->disableOptionWhen(fn ($value): bool => blank($value))
                ->disabled(fn (): bool => $this->filterStringList('base_names') === [])
                ->getOptionLabelUsing(fn ($value): ?string => Item::query()
                    ->whereKey($value)
                    ->get(['name', 'sub_item'])
                    ->map(fn (Item $item): string => filled($item->sub_item)
                        ? (string) $item->sub_item
                        : (string) $item->name)
                    ->first()),
        ];

        if ($includeMovingAverage) {
            $components[] = Toggle::make('show_moving_average')
                ->label('3-month moving average')
                ->default(false)
                ->inline(false)
                ->columnSpanFull();
        }

        return $components;
    }

    /**
     * @return array<int>
     */
    protected function filterIdList(string $key): array
    {
        return $this->normalizeIds($this->filters[$key] ?? null);
    }

    /**
     * @return array<int, string>
     */
    protected function filterStringList(string $key): array
    {
        $raw = $this->filters[$key] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $values,
        )));
    }

    protected function filterOptionsKey(string $key): string
    {
        $values = $key === 'base_names'
            ? $this->filterStringList($key)
            : array_map(strval(...), $this->filterIdList($key));

        return implode('-', $values) ?: 'none';
    }

    /**
     * @return array<int>
     */
    protected function defaultConsumptionOfficeIds(): array
    {
        $inventoryOfficeId = CustodianOfficeScope::inventoryOfficeId(Filament::auth()->user());

        if ($inventoryOfficeId !== null) {
            return [$inventoryOfficeId];
        }

        $userOfficeId = Filament::auth()->user()?->office_id;
        if ($userOfficeId) {
            return [(int) $userOfficeId];
        }

        $regionalId = Office::query()
            ->active()
            ->where('is_regional_supply', true)
            ->value('id');

        return $regionalId ? [(int) $regionalId] : [];
    }

    /**
     * @return array<string, string>
     */
    protected function consumptionOfficeOptions(): array
    {
        $user = Filament::auth()->user();
        $scope = $user?->getConsumptionScope() ?? ['office_ids' => [], 'department_ids' => []];
        $officeBase = Office::query()->active()->orderBy('name');

        $query = $scope['office_ids'] !== []
            ? (clone $officeBase)->whereIn('id', $scope['office_ids'])
            : $officeBase;

        return $query->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function departmentOptionsForOffices(mixed $officeIds): array
    {
        $ids = $this->normalizeIds($officeIds);
        if ($ids === []) {
            return [];
        }

        $user = Filament::auth()->user();
        $scope = $user?->getConsumptionScope() ?? ['office_ids' => [], 'department_ids' => []];

        $query = Department::query()
            ->active()
            ->whereIn('office_id', $ids)
            ->orderBy('name');

        if ($scope['department_ids'] !== []) {
            $query->whereIn('id', $scope['department_ids']);
        }

        return $query->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @return array<int>
     */
    protected function normalizeIds(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }

        return collect(is_array($values) ? $values : [$values])
            ->flatten()
            ->filter(fn ($id): bool => filled($id) && is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{
     *     department_ids: array<int>,
     *     office_ids: array<int>,
     *     item_ids: array<int>,
     *     from: \Illuminate\Support\Carbon,
     *     to: \Illuminate\Support\Carbon,
     *     includeYearInLabels: bool
     * }
     */
    protected function resolveConsumptionFilters(?array $filters = null): array
    {
        $f = $filters ?? $this->filters ?? [];
        $resolved = app(AnalyticsDateRangeService::class)->resolveFromWidgetFilters($f);
        $departmentIds = $this->normalizeIds($f['department_ids'] ?? []);
        $officeIds = $this->normalizeIds($f['office_ids'] ?? []);
        $itemIds = $this->resolveConsumptionItemIds($f);

        $user = Filament::auth()->user();
        if ($user) {
            $scope = $user->getConsumptionScope();
            if ($scope['office_ids'] !== [] || $scope['department_ids'] !== []) {
                $officeIds = $scope['office_ids'];
                $departmentIds = $scope['department_ids'];
            }
        }

        return [
            'department_ids' => $departmentIds,
            'office_ids' => $officeIds,
            'item_ids' => $itemIds,
            'from' => $resolved['from'],
            'to' => $resolved['to'],
            'includeYearInLabels' => $resolved['includeYearInLabels'],
        ];
    }

    protected function isConsumptionItemScoped(?array $filters = null): bool
    {
        return $this->resolveConsumptionFilters($filters)['item_ids'] !== [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int>
     */
    protected function resolveConsumptionItemIds(array $filters): array
    {
        $itemIds = $this->normalizeIds($filters['item_ids'] ?? []);
        if ($itemIds !== []) {
            return $itemIds;
        }

        $baseNames = $this->normalizeStringFilterValues($filters['base_names'] ?? null);
        $categoryIds = $this->normalizeIds($filters['item_category_ids'] ?? null);

        if ($baseNames === [] && $categoryIds === []) {
            return [];
        }

        $query = Item::query()->active();

        if ($categoryIds !== []) {
            $query->whereIn('item_category_id', $categoryIds);
        }

        if ($baseNames !== []) {
            $query->where(function ($inner) use ($baseNames): void {
                $inner->whereIn('base_name', $baseNames)
                    ->orWhere(function ($legacy) use ($baseNames): void {
                        $legacy->whereNull('base_name')->whereIn('name', $baseNames);
                    });
            });
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeStringFilterValues(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }

        $list = is_array($values) ? $values : [$values];

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $list,
        )));
    }
}
