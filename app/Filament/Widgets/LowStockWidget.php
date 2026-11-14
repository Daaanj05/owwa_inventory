<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\ProcurementAnalytics;
use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\AcquisitionPaperwork;
use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Models\PropertyActionRequest;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\SemiExpendableEulAnalyticsService;
use App\Support\DashboardKpiCache;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

    public int $kpiLowStockPage = 1;

    public int $kpiPendingPage = 1;

    public int $kpiIncomingPage = 1;

    public int $kpiUsefulLifePage = 1;

    public string $kpiPendingTab = 'requisition';

    public string $kpiOrdersTab = 'pr';

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
            return [
                'default' => 1,
                'md' => 2,
                'xl' => 4,
            ];
        }

        return $this->columns;
    }

    public function setKpiPage(string $key, int $page): void
    {
        $page = max(1, $page);

        match ($key) {
            'low_stock' => $this->kpiLowStockPage = $page,
            'pending' => $this->kpiPendingPage = $page,
            'incoming' => $this->kpiIncomingPage = $page,
            'useful_life' => $this->kpiUsefulLifePage = $page,
            default => null,
        };
    }

    public function setKpiTab(string $group, string $tab): void
    {
        if ($group === 'pending') {
            if (! in_array($tab, ['requisition', 'return'], true)) {
                return;
            }

            $this->kpiPendingTab = $tab;
            $this->kpiPendingPage = 1;

            return;
        }

        if ($group === 'orders') {
            if (! in_array($tab, ['pr', 'po', 'inspect'], true)) {
                return;
            }

            $this->kpiOrdersTab = $tab;
            $this->kpiIncomingPage = 1;
        }
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
                ->description(($lowStockCount > 0 ? 'At or below reorder point' : 'All stocks healthy').$scopeLabel)
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
        /** @var array{pending: int, orders_and_approvals: int} $kpiCounts */
        $kpiCounts = DashboardKpiCache::remember('supply_custodian', function (): array {
            return [
                'pending' => $this->computePendingActionsCount(),
                'orders_and_approvals' => $this->computeOrdersAndApprovalsCount(),
            ];
        });

        $pendingCount = $kpiCounts['pending'];
        $ordersAndApprovalsCount = $kpiCounts['orders_and_approvals'];
        $usefulLifeDueCount = $this->usefulLifeDueCount();

        return [
            Stat::make('Items running low', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Need restocking' : 'All stocks healthy').$scopeLabel)
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => "mountAction('viewLowStock')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Useful Life Due', $usefulLifeDueCount)
                ->description($usefulLifeDueCount > 0
                    ? 'Nearing or past useful life'
                    : 'All within useful life')
                ->descriptionIcon($usefulLifeDueCount > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($usefulLifeDueCount > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => "mountAction('viewUsefulLifeDue')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Pending requests & returns', $pendingCount)
                ->description($pendingCount > 0
                    ? 'Requests & returns awaiting your action'
                    : 'Nothing awaiting action')
                ->descriptionIcon($pendingCount > 0 ? 'heroicon-o-bell-alert' : 'heroicon-o-check-circle')
                ->color($pendingCount > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => 'openPendingRequisitionsModal',
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Orders and approvals', number_format($ordersAndApprovalsCount))
                ->description($ordersAndApprovalsCount > 0
                    ? 'POs to receive, and PR or PO awaiting approval'
                    : 'Nothing awaiting action')
                ->descriptionIcon('heroicon-o-truck')
                ->color($ordersAndApprovalsCount > 0 ? 'info' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable owwa-kpi-square',
                    'wire:click' => 'openOrdersAndApprovalsModal',
                    'title' => 'Pending PR or PO approval, and approved orders not yet received. Click to view details',
                ], merge: true),
        ];
    }

    public function openPendingRequisitionsModal(): void
    {
        $this->kpiPendingTab = 'requisition';
        $this->kpiPendingPage = 1;
        $this->mountAction('viewPendingRequisitions');
    }

    public function openOrdersAndApprovalsModal(): void
    {
        $this->kpiOrdersTab = 'pr';
        $this->kpiIncomingPage = 1;
        $this->mountAction('viewOrdersToInspect');
    }

    public function viewLowStockAction(): Action
    {
        return $this->detailModalAction(
            'viewLowStock',
            'Items running low',
            fn (): array => $this->lowStockDetail(),
            StockLevels::getUrl(),
            'Open Stock Levels',
        );
    }

    public function viewUsefulLifeDueAction(): Action
    {
        return $this->detailModalAction(
            'viewUsefulLifeDue',
            'Useful Life Due',
            fn (): array => $this->usefulLifeDueDetail(),
            ProcurementAnalytics::getUrl([
                'analyticsSlide' => 'replacement',
            ]),
            'Open Procurement Recommendation',
        );
    }

    public function viewPendingRequisitionsAction(): Action
    {
        return $this->detailModalAction(
            'viewPendingRequisitions',
            'Pending requests & returns',
            fn (): array => $this->pendingActionsDetail(),
            fn (): string => $this->kpiPendingTab === 'return'
                ? PropertyActionRequestResource::getUrl('index')
                : RequisitionResource::getUrl('index'),
            fn (): string => $this->kpiPendingTab === 'return'
                ? 'Open Property Returns'
                : 'Open Requisitions',
        );
    }

    public function viewOrdersToInspectAction(): Action
    {
        return $this->detailModalAction(
            'viewOrdersToInspect',
            'Orders and approvals',
            fn (): array => $this->ordersAndApprovalsDetail(),
            fn (): string => $this->kpiOrdersTab === 'pr'
                ? $this->purchaseRequestsIndexUrl()
                : $this->purchaseOrdersIndexUrl(),
            fn (): string => $this->kpiOrdersTab === 'pr'
                ? 'Open Purchase Requests'
                : 'Open Purchase Orders',
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $detailResolver
     * @param  string|callable(): string  $pageUrl
     * @param  string|callable(): string  $pageLabel
     */
    protected function detailModalAction(
        string $name,
        string $heading,
        callable $detailResolver,
        string|callable $pageUrl,
        string|callable $pageLabel,
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
    protected function lowStockDetail(): array
    {
        $rows = $this->lowStockRows();

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::KPI_PER_PAGE));
        $page = min(max(1, $this->kpiLowStockPage), $lastPage);
        $slice = $rows->forPage($page, self::KPI_PER_PAGE);

        return [
            'summary' => number_format($total).' low-stock item'
                .($total === 1 ? '' : 's')
                .' (at or below reorder point).',
            'empty_title' => 'All stocks healthy',
            'empty_desc' => 'No items are currently at or below their reorder point.',
            'columns' => [
                'item' => 'Item',
                'office' => 'Office',
                'stock' => 'On hand',
                'reorder_level' => 'Reorder point',
            ],
            'numeric_keys' => ['stock', 'reorder_level'],
            'rows' => $slice->map(fn (object $row): array => [
                'item' => $row->item_name,
                'office' => $row->office_name,
                'stock' => $row->stock,
                'reorder_level' => $row->reorder_level,
            ])->all(),
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
     *     item_name: string,
     *     office_name: string,
     *     stock: int,
     *     reorder_level: int
     * }>
     */
    protected function lowStockRows(): Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $this->stockOfficeIds($user instanceof User ? $user : null);
        $officeId = ($officeIds !== null && count($officeIds) === 1) ? $officeIds[0] : null;

        return app(InventoryStockService::class)
            ->getStockLevelsList(null, $officeId)
            ->when(
                $officeIds !== null && $officeId === null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (object $row): bool => in_array((int) $row->office_id, $officeIds, true),
                ),
            )
            ->filter(fn (object $row): bool => (bool) $row->is_low)
            ->groupBy(fn (object $row): string => "{$row->item_id}_{$row->office_id}")
            ->map(function (Collection $group): object {
                $first = $group->first();

                return (object) [
                    'item_name' => $first->item_name,
                    'office_name' => $first->office_name,
                    'stock' => (int) $group->sum('stock'),
                    'reorder_level' => (int) $first->reorder_level,
                ];
            })
            ->sortBy(['item_name', 'office_name'])
            ->values();
    }

    protected function usefulLifeDueCount(): int
    {
        return $this->usefulLifeDueRows()->count();
    }

    /**
     * @return Collection<int, object>
     */
    protected function usefulLifeDueRows(): Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $this->stockOfficeIds($user instanceof User ? $user : null) ?? [];

        return app(SemiExpendableEulAnalyticsService::class)->dueRows($officeIds);
    }

    /**
     * @return array<string, mixed>
     */
    protected function usefulLifeDueDetail(): array
    {
        $rows = $this->usefulLifeDueRows();
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::KPI_PER_PAGE));
        $page = min(max(1, $this->kpiUsefulLifePage), $lastPage);
        $slice = $rows->forPage($page, self::KPI_PER_PAGE);

        return [
            'summary' => number_format($total).' semi-expendable unit'
                .($total === 1 ? '' : 's')
                .' nearing or past useful life.',
            'empty_title' => 'All within useful life',
            'empty_desc' => 'No issued semi-expendable units are nearing or past useful life.',
            'columns' => [
                'item' => 'Item',
                'property_number' => 'Property number',
                'issued_to' => 'Issued to',
                'expires' => 'Expires',
                'status' => 'Status',
            ],
            'numeric_keys' => [],
            'rows' => $slice->map(fn (object $row): array => [
                'item' => $row->item_name,
                'property_number' => $row->property_number,
                'issued_to' => $row->issued_to_name,
                'expires' => filled($row->eul_expires_at)
                    ? Carbon::parse($row->eul_expires_at)->format('M j, Y')
                    : null,
                'status' => $row->status_label,
            ])->all(),
            'pagination' => [
                'key' => 'useful_life',
                'current' => $page,
                'last' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pendingActionsDetail(): array
    {
        $cached = $this->cachedPendingModalRows();
        $requisitionRows = collect($cached['requisition']);
        $returnRows = collect($cached['return']);
        $combinedTotal = $requisitionRows->count() + $returnRows->count();

        $activeTab = in_array($this->kpiPendingTab, ['requisition', 'return'], true)
            ? $this->kpiPendingTab
            : 'requisition';

        $rows = $activeTab === 'return' ? $returnRows : $requisitionRows;
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::KPI_PER_PAGE));
        $page = min(max(1, $this->kpiPendingPage), $lastPage);
        $slice = $rows->forPage($page, self::KPI_PER_PAGE)->values();

        return [
            'summary' => number_format($combinedTotal).' pending request'.($combinedTotal === 1 ? '' : 's').' & return'.($combinedTotal === 1 ? '' : 's').' awaiting your action.',
            'empty_title' => $activeTab === 'return'
                ? 'No pending property returns'
                : 'No pending requisitions',
            'empty_desc' => $activeTab === 'return'
                ? 'There are no property returns awaiting Supply Custodian action.'
                : 'There are no requisitions awaiting Supply Custodian action.',
            'columns' => [
                'reference' => 'Reference',
                'transaction_type' => 'Transaction',
                'office' => 'Office',
                'requested_by' => 'Requested by',
                'created' => 'Submitted',
            ],
            'numeric_keys' => [],
            'rows' => $slice->all(),
            'tabs' => [
                [
                    'key' => 'requisition',
                    'label' => 'Pending Requisition',
                    'count' => $requisitionRows->count(),
                ],
                [
                    'key' => 'return',
                    'label' => 'Pending Property Return',
                    'count' => $returnRows->count(),
                ],
            ],
            'tab_group' => 'pending',
            'active_tab' => $activeTab,
            'pagination' => [
                'key' => 'pending',
                'current' => $page,
                'last' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{requisition: array<int, array<string, mixed>>, return: array<int, array<string, mixed>>}
     */
    protected function cachedPendingModalRows(): array
    {
        /** @var array{requisition: array<int, array<string, mixed>>, return: array<int, array<string, mixed>>} $rows */
        $rows = DashboardKpiCache::remember('supply_custodian.pending_modal_rows', function (): array {
            return [
                'requisition' => $this->buildPendingRequisitionRows()->all(),
                'return' => $this->buildPendingPropertyReturnRows()->all(),
            ];
        });

        return $rows;
    }

    protected function computePendingActionsCount(): int
    {
        return $this->pendingRequisitionsQuery()->count()
            + $this->remainderRequisitionsQuery()->count()
            + $this->pendingPropertyReturnsQuery()->count();
    }

    protected function computeOrdersAndApprovalsCount(): int
    {
        return $this->pendingPrApprovalQuery()->count()
            + $this->pendingPoApprovalQuery()->count()
            + $this->ordersToInspectQuery()->count();
    }

    /**
     * @return Collection<int, array{reference: string, transaction_type: string, office: ?string, requested_by: ?string, created: ?string, record_url: string}>
     */
    protected function buildPendingRequisitionRows(): Collection
    {
        $rows = collect();

        $pendingRequisitions = $this->pendingRequisitionsQuery()
            ->with(['requestedBy', 'office'])
            ->latest('created_at')
            ->get();

        foreach ($pendingRequisitions as $requisition) {
            $rows->push($this->mapPendingRequisitionRow($requisition, 'Requisition'));
        }

        $remainderRequisitions = $this->remainderRequisitionsQuery()
            ->with(['requestedBy', 'office'])
            ->latest('created_at')
            ->get();

        foreach ($remainderRequisitions as $requisition) {
            $rows->push($this->mapPendingRequisitionRow($requisition, 'Issue remainder'));
        }

        return $rows
            ->sortByDesc(fn (array $row): string => (string) ($row['created_at'] ?? ''))
            ->map(function (array $row): array {
                unset($row['created_at']);

                return $row;
            })
            ->values();
    }

    /**
     * @return array{reference: string, transaction_type: string, office: ?string, requested_by: ?string, created: ?string, created_at: string, record_url: string}
     */
    protected function mapPendingRequisitionRow(Requisition $requisition, string $transactionType): array
    {
        return [
            'reference' => $requisition->displayRisNumber() ?? $requisition->reference_code ?? '#'.$requisition->id,
            'transaction_type' => $transactionType,
            'office' => $requisition->office?->name,
            'requested_by' => $requisition->requestedBy?->name,
            'created' => optional($requisition->created_at)?->format('M j, Y'),
            'created_at' => optional($requisition->created_at)?->toIso8601String() ?? '',
            'record_url' => RequisitionResource::viewModalUrl($requisition),
        ];
    }

    /**
     * @return Collection<int, array{reference: string, transaction_type: string, office: ?string, requested_by: ?string, created: ?string, record_url: string}>
     */
    protected function buildPendingPropertyReturnRows(): Collection
    {
        return $this->pendingPropertyReturnsQuery()
            ->with(['requestedBy', 'office'])
            ->latest('created_at')
            ->get()
            ->map(fn (PropertyActionRequest $request): array => [
                'reference' => $request->reference_code ?? '#'.$request->id,
                'transaction_type' => 'Property return',
                'office' => $request->office?->name,
                'requested_by' => $request->requestedBy?->name,
                'created' => optional($request->created_at)?->format('M j, Y'),
                'record_url' => PropertyActionRequestResource::viewModalUrl($request),
            ])
            ->values();
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

    /**
     * Accepted UC requisitions with at least one line still to issue.
     *
     * @return Builder<Requisition>
     */
    protected function remainderRequisitionsQuery(): Builder
    {
        return Requisition::query()
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereHas('requestedBy', function (Builder $q): void {
                $q->where('role', User::ROLE_UNIT_CONSOLIDATOR);
            })
            ->whereHas('items', function (Builder $q): void {
                $q->whereRaw('quantity > COALESCE(quantity_issued, 0)');
            });
    }

    /**
     * @return Builder<PropertyActionRequest>
     */
    protected function pendingPropertyReturnsQuery(): Builder
    {
        return PropertyActionRequest::query()
            ->where('status', PropertyActionRequest::STATUS_PENDING_SC);
    }

    /**
     * @return Builder<AcquisitionPaperwork>
     */
    protected function pendingPrApprovalQuery(): Builder
    {
        return AcquisitionPaperwork::query()
            ->whereNull('archived_at')
            ->where('pr_status', AcquisitionPaperwork::STATUS_PENDING_APPROVAL);
    }

    /**
     * @return Builder<PurchaseOrder>
     */
    protected function pendingPoApprovalQuery(): Builder
    {
        return PurchaseOrder::query()
            ->whereNull('archived_at')
            ->where('status', PurchaseOrder::STATUS_PENDING_APPROVAL);
    }

    /**
     * Approved POs not yet received into stock (IAR may be missing or in progress).
     *
     * @return Builder<PurchaseOrder>
     */
    protected function ordersToInspectQuery(): Builder
    {
        return PurchaseOrder::query()
            ->whereNull('archived_at')
            ->where('status', PurchaseOrder::STATUS_APPROVED)
            ->whereHas('purchaseRequest', fn (Builder $q): Builder => $q
                ->whereNull('archived_at')
                ->whereNull('received_at'))
            ->where(fn (Builder $q): Builder => $q
                ->whereDoesntHave('inspectionAcceptanceReport')
                ->orWhereHas(
                    'inspectionAcceptanceReport',
                    fn (Builder $iar): Builder => $iar->whereNull('stock_received_at'),
                ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function ordersAndApprovalsDetail(): array
    {
        $cached = $this->cachedOrdersModalRows();
        $prRows = $cached['pr'];
        $poRows = $cached['po'];
        $inspectRows = $cached['inspect'];
        $prCount = count($prRows);
        $poCount = count($poRows);
        $inspectCount = count($inspectRows);
        $combinedTotal = $prCount + $poCount + $inspectCount;

        $activeTab = in_array($this->kpiOrdersTab, ['pr', 'po', 'inspect'], true)
            ? $this->kpiOrdersTab
            : 'pr';

        $detail = match ($activeTab) {
            'po' => $this->paginateOrdersTabDetail(
                $poRows,
                'No purchase orders pending approval',
                'There are no purchase orders awaiting approval.',
                [
                    'po_number' => 'PO number',
                    'supplier' => 'Supplier',
                    'status' => 'Status',
                    'office' => 'Office',
                    'date' => 'Submitted',
                ],
            ),
            'inspect' => $this->paginateOrdersTabDetail(
                $inspectRows,
                'Approved POs still waiting to be received',
                'There are no approved purchase orders waiting to be inspected or received into stock.',
                [
                    'po_number' => 'PO number',
                    'supplier' => 'Supplier',
                    'status' => 'Status',
                    'office' => 'Office',
                    'date' => 'PO date',
                ],
            ),
            default => $this->paginateOrdersTabDetail(
                $prRows,
                'No purchase requests pending approval',
                'There are no purchase requests awaiting approval.',
                [
                    'pr_number' => 'PR number',
                    'purpose' => 'Purpose',
                    'office' => 'Office',
                    'status' => 'Status',
                    'date' => 'Submitted',
                ],
            ),
        };

        return array_merge($detail, [
            'summary' => number_format($combinedTotal).' order'.($combinedTotal === 1 ? '' : 's')
                .' and approval'.($combinedTotal === 1 ? '' : 's')
                .' awaiting your action.',
            'tabs' => [
                [
                    'key' => 'pr',
                    'label' => 'Pending PR approval',
                    'count' => $prCount,
                ],
                [
                    'key' => 'po',
                    'label' => 'PO approval',
                    'count' => $poCount,
                ],
                [
                    'key' => 'inspect',
                    'label' => 'Orders to inspect & receive',
                    'count' => $inspectCount,
                ],
            ],
            'tab_group' => 'orders',
            'active_tab' => $activeTab,
        ]);
    }

    /**
     * @return array{pr: array<int, array<string, mixed>>, po: array<int, array<string, mixed>>, inspect: array<int, array<string, mixed>>}
     */
    protected function cachedOrdersModalRows(): array
    {
        /** @var array{pr: array<int, array<string, mixed>>, po: array<int, array<string, mixed>>, inspect: array<int, array<string, mixed>>} $rows */
        $rows = DashboardKpiCache::remember('supply_custodian.orders_modal_rows', function (): array {
            return [
                'pr' => $this->buildPendingPrApprovalRows(),
                'po' => $this->buildPendingPoApprovalRows(),
                'inspect' => $this->buildOrdersToInspectRows(),
            ];
        });

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    protected function paginateOrdersTabDetail(
        array $rows,
        string $emptyTitle,
        string $emptyDesc,
        array $columns,
    ): array {
        $collection = collect($rows);
        $total = $collection->count();
        $lastPage = max(1, (int) ceil($total / self::KPI_PER_PAGE));
        $page = min(max(1, $this->kpiIncomingPage), $lastPage);

        return [
            'empty_title' => $emptyTitle,
            'empty_desc' => $emptyDesc,
            'columns' => $columns,
            'numeric_keys' => [],
            'rows' => $collection->forPage($page, self::KPI_PER_PAGE)->values()->all(),
            'pagination' => [
                'key' => 'incoming',
                'current' => $page,
                'last' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildPendingPrApprovalRows(): array
    {
        return $this->pendingPrApprovalQuery()
            ->with(['office', 'itemCategory'])
            ->latest('pr_submitted_at')
            ->latest('created_at')
            ->get()
            ->map(function (AcquisitionPaperwork $paperwork): array {
                $categoryId = (int) ($paperwork->item_category_id ?? 0);

                return [
                    'pr_number' => $paperwork->pr_number ?? $paperwork->reference_code ?? '#'.$paperwork->id,
                    'purpose' => $paperwork->purpose,
                    'office' => $paperwork->office?->name,
                    'status' => $paperwork->workflowStatusLabel(),
                    'date' => optional($paperwork->pr_submitted_at ?? $paperwork->created_at)?->format('M j, Y'),
                    'record_url' => AcquisitionResource::viewModalUrl($paperwork, array_filter([
                        'category' => $categoryId > 0 ? $categoryId : null,
                    ])),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildPendingPoApprovalRows(): array
    {
        return $this->pendingPoApprovalQuery()
            ->with(['purchaseRequest.office', 'supplier'])
            ->latest('submitted_at')
            ->latest('created_at')
            ->get()
            ->map(function (PurchaseOrder $order): array {
                $categoryId = (int) ($order->purchaseRequest?->item_category_id ?? 0);

                return [
                    'po_number' => $order->number ?? '#'.$order->id,
                    'supplier' => $order->supplier_name ?: ($order->supplier?->name ?? '—'),
                    'status' => $order->statusLabel(),
                    'office' => $order->purchaseRequest?->office?->name,
                    'date' => optional($order->submitted_at ?? $order->po_date)?->format('M j, Y'),
                    'record_url' => PurchaseOrderResource::viewModalUrl($order, array_filter([
                        'category' => $categoryId > 0 ? $categoryId : null,
                    ])),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildOrdersToInspectRows(): array
    {
        return $this->ordersToInspectQuery()
            ->with(['purchaseRequest.office', 'inspectionAcceptanceReport', 'supplier'])
            ->latest('approved_at')
            ->latest('po_date')
            ->get()
            ->map(function (PurchaseOrder $order): array {
                $categoryId = (int) ($order->purchaseRequest?->item_category_id ?? 0);

                return [
                    'po_number' => $order->number ?? '#'.$order->id,
                    'supplier' => $order->supplier_name ?: ($order->supplier?->name ?? '—'),
                    'status' => $order->statusLabel(),
                    'office' => $order->purchaseRequest?->office?->name,
                    'date' => optional($order->po_date ?? $order->approved_at)?->format('M j, Y'),
                    'record_url' => PurchaseOrderResource::viewModalUrl($order, array_filter([
                        'category' => $categoryId > 0 ? $categoryId : null,
                    ])),
                ];
            })
            ->all();
    }

    protected function purchaseOrdersIndexUrl(): string
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

        if ($categoryId <= 0) {
            $categoryId = (int) ItemCategory::query()->orderBy('name')->value('id');
        }

        return PurchaseOrderResource::getUrl('index', array_filter([
            'category' => $categoryId > 0 ? $categoryId : null,
        ]));
    }

    protected function purchaseRequestsIndexUrl(): string
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

        if ($categoryId <= 0) {
            $categoryId = (int) ItemCategory::query()->orderBy('name')->value('id');
        }

        return AcquisitionResource::getUrl('index', array_filter([
            'category' => $categoryId > 0 ? $categoryId : null,
        ]));
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
