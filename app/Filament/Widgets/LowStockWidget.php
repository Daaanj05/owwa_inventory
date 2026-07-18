<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\User;
use App\Services\CatalogAssetNumberService;
use App\Services\InventoryStockService;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class LowStockWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = 2;

    protected string $view = 'filament.widgets.low-stock-widget';

    public int $kpiItemsPage = 1;

    public int $kpiLowStockPage = 1;

    public int $kpiPendingPage = 1;

    public ?int $kpiItemsCategoryId = null;

    public ?int $kpiLowStockCategoryId = null;

    protected const int KPI_PER_PAGE = 10;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user
            && ! $user->isSystemAdmin()
            && ! $user->isEmployee()
            && ! $user->isUnitConsolidator();
    }

    /**
     * @return int|array<string, ?int>|null
     */
    protected function getColumns(): int|array|null
    {
        $user = Filament::auth()->user();

        if ($user?->isSupplyCustodian()) {
            return 3;
        }

        return $this->columns;
    }

    public function setKpiPage(string $key, int $page): void
    {
        $page = max(1, $page);

        match ($key) {
            'items' => $this->kpiItemsPage = $page,
            'low_stock' => $this->kpiLowStockPage = $page,
            'pending' => $this->kpiPendingPage = $page,
            default => null,
        };
    }

    public function setKpiCategory(string $key, ?string $categoryId): void
    {
        $resolved = filled($categoryId) ? (int) $categoryId : null;

        match ($key) {
            'items' => [$this->kpiItemsCategoryId = $resolved, $this->kpiItemsPage = 1],
            'low_stock' => [$this->kpiLowStockCategoryId = $resolved, $this->kpiLowStockPage = 1],
            default => null,
        };
    }

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        if (! $user) {
            return [];
        }

        $officeIds = $this->stockOfficeIds($user);
        $scopeLabel = (! $user->isSupplyCustodian() && $user->office_id) ? ' (your office)' : '';

        $stockService = app(InventoryStockService::class);
        $lowStockCount = $stockService->lowStockCount($officeIds);

        if ($user->isSupplyCustodian()) {
            return $this->buildSupplyCustodianStats($lowStockCount, $scopeLabel);
        }

        return [
            Stat::make('Low stock', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Below reorder point' : 'All stocks healthy').$scopeLabel)
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true),

            $this->buildIssuedThisMonthStat($scopeLabel, $officeIds),
        ];
    }

    /**
     * Low stock is scoped to the user's assigned office (custodian = regional supply office).
     *
     * @return array<int, int>|null
     */
    protected function stockOfficeIds(?User $user): ?array
    {
        if ($user === null || ! $user->office_id) {
            return null;
        }

        return [(int) $user->office_id];
    }

    /**
     * @return array<Stat>
     */
    protected function buildSupplyCustodianStats(int $lowStockCount, string $scopeLabel): array
    {
        $itemsInTotal = Item::query()->active()->count();
        $pendingCount = $this->pendingRequisitionsQuery()->count();

        return [
            Stat::make('Items in total', number_format($itemsInTotal))
                ->description('Registered items in catalog')
                ->descriptionIcon('heroicon-o-cube')
                ->color('primary')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => "mountAction('viewItemsInTotal')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Low stock', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Below reorder point' : 'All stocks healthy').$scopeLabel)
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => "mountAction('viewLowStock')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Pending requisitions', $pendingCount)
                ->description($pendingCount > 0
                    ? $pendingCount.' '.str('requisition')->plural($pendingCount).' awaiting your action'
                    : 'No pending requisitions')
                ->descriptionIcon($pendingCount > 0 ? 'heroicon-o-bell-alert' : 'heroicon-o-check-circle')
                ->color($pendingCount > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => "mountAction('viewPendingRequisitions')",
                    'title' => 'Click to view details',
                ], merge: true),
        ];
    }

    public function viewItemsInTotalAction(): Action
    {
        return $this->detailModalAction(
            'viewItemsInTotal',
            'Items in total',
            fn (): array => $this->itemsInTotalDetail(),
            ItemResource::getUrl('index'),
            'Open Items',
        );
    }

    public function viewLowStockAction(): Action
    {
        return $this->detailModalAction(
            'viewLowStock',
            'Low stock',
            fn (): array => $this->lowStockDetail(),
            StockLevels::getUrl(),
            'Open Stock Levels',
        );
    }

    public function viewPendingRequisitionsAction(): Action
    {
        return $this->detailModalAction(
            'viewPendingRequisitions',
            'Pending requisitions',
            fn (): array => $this->pendingRequisitionsDetail(),
            RequisitionResource::getUrl('index'),
            'Open Requisitions',
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $detailResolver
     */
    protected function detailModalAction(
        string $name,
        string $heading,
        callable $detailResolver,
        string $pageUrl,
        string $pageLabel,
    ): Action {
        return Action::make($name)
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading($heading)
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.widgets.partials.employee-stats-detail-modal',
                ['detail' => $detailResolver()],
            )->render()))
            ->extraModalFooterActions([
                Action::make('openPage')
                    ->label($pageLabel)
                    ->url($pageUrl)
                    ->color('primary')
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemsInTotalDetail(): array
    {
        $query = Item::query()
            ->active()
            ->with('category')
            ->when(
                $this->kpiItemsCategoryId,
                fn (Builder $q): Builder => $q->where('item_category_id', $this->kpiItemsCategoryId),
            )
            ->orderBy(
                ItemCategory::query()
                    ->select('name')
                    ->whereColumn('item_categories.id', 'items.item_category_id')
                    ->limit(1)
            )
            ->orderBy('name');

        $paginator = $query->paginate(self::KPI_PER_PAGE, ['*'], 'page', $this->kpiItemsPage);

        $sections = [];
        foreach ($paginator->getCollection()->groupBy(fn (Item $item): string => $item->category?->name ?? 'Uncategorized') as $categoryName => $items) {
            $first = $items->first();
            $identifierLabel = app(CatalogAssetNumberService::class)
                ->catalogIdentifierLabel($first?->category?->getTemplateSlug());

            $sections[] = [
                'heading' => $categoryName,
                'columns' => [
                    'identifier' => $identifierLabel,
                    'name' => 'Item',
                    'unit' => 'Unit',
                    'reorder_level' => 'Reorder point',
                ],
                'rows' => $items->map(fn (Item $item): array => [
                    'identifier' => $item->catalogAssetIdentifier(),
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'reorder_level' => $item->reorder_level,
                ])->values()->all(),
            ];
        }

        $selectedLabel = $this->selectedCategoryLabel($this->kpiItemsCategoryId);

        return [
            'summary' => number_format($paginator->total()).' registered item'.($paginator->total() === 1 ? '' : 's')
                .($selectedLabel ? " in {$selectedLabel}" : ' across categories').'.',
            'empty_title' => 'No items',
            'empty_desc' => $selectedLabel
                ? "There are no active items in {$selectedLabel}."
                : 'There are no active items in the catalog yet.',
            'columns' => [
                'identifier' => 'Identifier',
                'name' => 'Item',
                'unit' => 'Unit',
                'reorder_level' => 'Reorder point',
            ],
            'numeric_keys' => ['reorder_level'],
            'sections' => $sections,
            'rows' => [],
            'category_filter' => [
                'key' => 'items',
                'value' => $this->kpiItemsCategoryId,
                'options' => InventoryCategoryOptions::allActiveCategoryOptions(),
            ],
            'pagination' => [
                'key' => 'items',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lowStockDetail(): array
    {
        $rows = $this->lowStockRows($this->kpiLowStockCategoryId);

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::KPI_PER_PAGE));
        $page = min(max(1, $this->kpiLowStockPage), $lastPage);
        $slice = $rows->forPage($page, self::KPI_PER_PAGE);

        $selectedLabel = $this->selectedCategoryLabel($this->kpiLowStockCategoryId);

        return [
            'summary' => number_format($total).' low-stock item'
                .($total === 1 ? '' : 's')
                .($selectedLabel ? " in {$selectedLabel}" : '')
                .' (below reorder point).',
            'empty_title' => 'All stocks healthy',
            'empty_desc' => $selectedLabel
                ? "No {$selectedLabel} items are below their reorder point."
                : 'No items are currently below their reorder point.',
            'columns' => [
                'category' => 'Category',
                'item' => 'Item',
                'office' => 'Office',
                'stock' => 'On hand',
                'reorder_level' => 'Reorder point',
            ],
            'numeric_keys' => ['stock', 'reorder_level'],
            'rows' => $slice->map(fn (object $row): array => [
                'category' => $row->category_name,
                'item' => $row->item_name,
                'office' => $row->office_name,
                'stock' => $row->stock,
                'reorder_level' => $row->reorder_level,
            ])->all(),
            'category_filter' => [
                'key' => 'low_stock',
                'value' => $this->kpiLowStockCategoryId,
                'options' => InventoryCategoryOptions::allActiveCategoryOptions(),
            ],
            'pagination' => [
                'key' => 'low_stock',
                'current' => $page,
                'last' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * One row per item × office so the modal matches {@see InventoryStockService::lowStockCount()}.
     *
     * @return Collection<int, object{
     *     category_name: string,
     *     item_name: string,
     *     office_name: string,
     *     stock: int,
     *     reorder_level: int
     * }>
     */
    protected function lowStockRows(?int $categoryId = null): Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $this->stockOfficeIds($user instanceof User ? $user : null);

        return app(InventoryStockService::class)
            ->getStockLevelsList($categoryId)
            ->when(
                $officeIds !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (object $row): bool => in_array((int) $row->office_id, $officeIds, true),
                ),
            )
            ->filter(fn (object $row): bool => (bool) $row->is_low)
            ->groupBy(fn (object $row): string => "{$row->item_id}_{$row->office_id}")
            ->map(function (Collection $group): object {
                $first = $group->first();

                return (object) [
                    'category_name' => $first->category_name,
                    'item_name' => $first->item_name,
                    'office_name' => $first->office_name,
                    'stock' => (int) $group->sum('stock'),
                    'reorder_level' => (int) $first->reorder_level,
                ];
            })
            ->sortBy(['category_name', 'item_name', 'office_name'])
            ->values();
    }

    protected function selectedCategoryLabel(?int $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        return ItemCategory::query()->whereKey($categoryId)->value('name');
    }

    /**
     * @return array<string, mixed>
     */
    protected function pendingRequisitionsDetail(): array
    {
        $paginator = $this->pendingRequisitionsQuery()
            ->with(['requestedBy', 'office', 'compiledIntoRequisition'])
            ->latest('created_at')
            ->paginate(self::KPI_PER_PAGE, ['*'], 'page', $this->kpiPendingPage);

        $rows = $paginator->getCollection()->map(fn (Requisition $requisition): array => [
            'ris_number' => $requisition->displayRisNumber(),
            'office' => $requisition->office?->name,
            'requested_by' => $requisition->requestedBy?->name,
            'purpose' => $requisition->purpose,
            'created' => optional($requisition->created_at)?->format('M j, Y'),
        ])->all();

        return [
            'summary' => number_format($paginator->total()).' pending UC requisition'.($paginator->total() === 1 ? '' : 's').'.',
            'empty_title' => 'No pending requisitions',
            'empty_desc' => 'There are no unit consolidator requisitions awaiting your action.',
            'columns' => [
                'ris_number' => OwwaReferenceLabels::requisition(),
                'office' => 'Office',
                'requested_by' => 'Requested by',
                'purpose' => 'Purpose',
                'created' => 'Submitted',
            ],
            'numeric_keys' => [],
            'rows' => $rows,
            'pagination' => [
                'key' => 'pending',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return Builder<Requisition>
     */
    protected function pendingRequisitionsQuery(): Builder
    {
        return Requisition::query()
            ->where('status', Requisition::STATUS_PENDING)
            ->whereHas('requestedBy', function (Builder $q): void {
                $q->where('role', User::ROLE_UNIT_CONSOLIDATOR);
            });
    }

    protected function buildIssuedThisMonthStat(string $scopeLabel, ?array $officeIds): Stat
    {
        $issuancesQuery = Issuance::query();
        $issuancesQuery->whereMonth('issuance_date', now()->month)
            ->whereYear('issuance_date', now()->year);
        if ($officeIds !== null) {
            $issuancesQuery->whereIn('office_id', $officeIds);
        }
        $issuancesThisMonth = $issuancesQuery->sum('quantity');

        return Stat::make('Issued this month', number_format($issuancesThisMonth))
            ->description(now()->format('M Y').' issuances'.$scopeLabel)
            ->descriptionIcon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->extraAttributes(['class' => 'owwa-kpi-square'], true);
    }
}
