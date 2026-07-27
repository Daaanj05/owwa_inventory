<?php

namespace App\Services;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\User;
use App\Support\OwwaReferenceLabels;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeDistributionInventoryService
{
    public const CATEGORY_CONSUMABLES = 'consumables';

    public const CATEGORY_SEMI_EXPENDABLE = 'semi_expendable';

    public const CATEGORY_PPE = 'ppe';

    public const CUSTODY_TAB_ON_HAND = 'on_hand';

    public const CUSTODY_TAB_HISTORY = 'history';

    public static function isValidCustodyTab(?string $tab): bool
    {
        return in_array($tab, [self::CUSTODY_TAB_ON_HAND, self::CUSTODY_TAB_HISTORY], true);
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_CONSUMABLES => 'Consumables',
            self::CATEGORY_SEMI_EXPENDABLE => 'Semi-Expendable',
            self::CATEGORY_PPE => 'Property, Plant and Equipment',
        ];
    }

    public static function isValidCategory(?string $category): bool
    {
        return filled($category) && array_key_exists($category, self::categoryOptions());
    }

    public static function usesPropertyIssuanceView(string $category): bool
    {
        return in_array($category, [self::CATEGORY_SEMI_EXPENDABLE, self::CATEGORY_PPE], true);
    }

    /**
     * @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int}
     */
    public function summaryFor(
        User $user,
        string $category = self::CATEGORY_CONSUMABLES,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        if (! self::isValidCategory($category)) {
            $category = self::CATEGORY_CONSUMABLES;
        }

        if (! self::isValidCustodyTab($custodyTab)) {
            $custodyTab = self::CUSTODY_TAB_ON_HAND;
        }

        if (self::usesPropertyIssuanceView($category)) {
            $slug = $category === self::CATEGORY_PPE ? self::CATEGORY_PPE : self::CATEGORY_SEMI_EXPENDABLE;
            $base = Issuance::query()
                ->where('issued_to', $user->id)
                ->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->whereIn(
                    'item_category_id',
                    $this->categoryIdsForSlug($slug),
                ));

            $this->applyPropertyCustodyTabFilter($base, $custodyTab);
            $this->applyDatePeriodFilter($base, 'issuance_date', $fromDate, $toDate);

            return [
                'totalItems' => (int) (clone $base)->distinct('item_id')->count('item_id'),
                'totalQuantity' => (int) (clone $base)->sum('quantity'),
                'totalQuantityThisYear' => (int) (clone $base)
                    ->whereBetween('issuance_date', [now()->startOfYear(), now()->endOfYear()])
                    ->sum('quantity'),
            ];
        }

        $base = Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereIn('item_id', $this->itemIdsForCategorySlug($category));
        $this->applyDatePeriodFilter($base, 'distribution_date', $fromDate, $toDate);

        return [
            'totalItems' => (int) (clone $base)->distinct('item_id')->count('item_id'),
            'totalQuantity' => (int) (clone $base)->sum('quantity'),
            'totalQuantityThisYear' => (int) (clone $base)
                ->whereBetween('distribution_date', [now()->startOfYear(), now()->endOfYear()])
                ->sum('quantity'),
        ];
    }

    /**
     * @return Builder<Distribution>
     */
    public function groupedInventoryQuery(
        User $user,
        ?string $search = null,
        string $category = self::CATEGORY_CONSUMABLES,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        if (! self::isValidCategory($category)) {
            $category = self::CATEGORY_CONSUMABLES;
        }

        $query = Distribution::query()
            ->select([
                'distributions.item_id',
                DB::raw('SUM(distributions.quantity) as total_quantity'),
                DB::raw('MAX(distributions.distribution_date) as last_distribution_date'),
                DB::raw('COUNT(*) as distribution_count'),
            ])
            ->join('items', 'items.id', '=', 'distributions.item_id')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->where('distributed_to', $user->id)
            ->whereIn('items.item_category_id', $this->categoryIdsForSlug($category))
            ->groupBy('distributions.item_id', 'items.name', 'item_categories.name')
            ->addSelect([
                'items.name as item_name',
                'item_categories.name as category_name',
            ]);
        $this->applyDatePeriodFilter($query, 'distributions.distribution_date', $fromDate, $toDate);

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('items.name', 'like', $term)
                    ->orWhere('item_categories.name', 'like', $term);
            });
        }

        return $query;
    }

    public function paginatedGroupedInventory(
        User $user,
        ?string $search,
        string $sortBy,
        string $sortDir,
        int $perPage = 10,
        string $category = self::CATEGORY_CONSUMABLES,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): LengthAwarePaginator {
        if (self::usesPropertyIssuanceView($category)) {
            return $this->paginatedPropertyIssuances($user, $search, $sortBy, $sortDir, $perPage, $category, $custodyTab, $fromDate, $toDate);
        }

        $query = $this->groupedInventoryQuery($user, $search, $category, $fromDate, $toDate);

        $sortColumn = match ($sortBy) {
            'item_name' => 'items.name',
            'category_name' => 'item_categories.name',
            'quantity' => 'total_quantity',
            'distribution_date' => 'last_distribution_date',
            'distribution_count' => 'distribution_count',
            default => 'last_distribution_date',
        };

        return $query
            ->orderBy($sortColumn, $sortDir)
            ->paginate($perPage)
            ->withQueryString()
            ->onEachSide(0);
    }

    /**
     * @return Builder<Issuance>
     */
    public function groupedPropertyIssuancesQuery(
        User $user,
        ?string $search,
        string $category,
        string $custodyTab,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        if (! self::isValidCustodyTab($custodyTab)) {
            $custodyTab = self::CUSTODY_TAB_ON_HAND;
        }

        $slug = $category === self::CATEGORY_PPE ? self::CATEGORY_PPE : self::CATEGORY_SEMI_EXPENDABLE;

        $query = Issuance::query()
            ->select([
                'issuances.item_id',
                DB::raw('SUM(issuances.quantity) as total_quantity'),
                DB::raw('COUNT(*) as distribution_count'),
                DB::raw('MAX(issuances.issuance_date) as last_distribution_date'),
            ])
            ->join('items', 'items.id', '=', 'issuances.item_id')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->where('issuances.issued_to', $user->id)
            ->whereIn('items.item_category_id', $this->categoryIdsForSlug($slug))
            ->groupBy('issuances.item_id', 'items.name', 'item_categories.name')
            ->addSelect([
                'items.name as item_name',
                'item_categories.name as category_name',
            ]);

        $this->applyPropertyCustodyTabFilter($query, $custodyTab);
        $this->applyDatePeriodFilter($query, 'issuances.issuance_date', $fromDate, $toDate);

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $scope) use ($term): void {
                $scope->where('items.name', 'like', $term)
                    ->orWhere('item_categories.name', 'like', $term)
                    ->orWhereExists(function ($sub) use ($term): void {
                        $sub->selectRaw('1')
                            ->from('issuances as search_issuances')
                            ->whereColumn('search_issuances.item_id', 'issuances.item_id')
                            ->whereColumn('search_issuances.issued_to', 'issuances.issued_to')
                            ->where('search_issuances.property_number', 'like', $term);
                    });
            });
        }

        return $query;
    }

    public function paginatedPropertyIssuances(
        User $user,
        ?string $search,
        string $sortBy,
        string $sortDir,
        int $perPage = 10,
        string $category = self::CATEGORY_SEMI_EXPENDABLE,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): LengthAwarePaginator {
        $query = $this->groupedPropertyIssuancesQuery($user, $search, $category, $custodyTab, $fromDate, $toDate);

        $sortColumn = match ($sortBy) {
            'item_name' => 'items.name',
            'quantity' => 'total_quantity',
            'distribution_date', 'last_distribution_date', 'issuance_date', 'last_issuance_date' => 'last_distribution_date',
            'distribution_count' => 'distribution_count',
            default => 'last_distribution_date',
        };

        $paginator = $query
            ->orderBy($sortColumn, $sortDir)
            ->paginate($perPage)
            ->withQueryString()
            ->onEachSide(0);

        return $this->enrichGroupedPropertyRows($paginator, $user, $category, $custodyTab);
    }

    /**
     * @param  Collection<int, Issuance>  $issuances
     */
    public function worstEulStatusForIssuances(Collection $issuances): ?string
    {
        $statuses = $issuances
            ->map(fn (Issuance $issuance): ?string => SemiExpendableUsefulLife::statusForIssuance($issuance))
            ->filter()
            ->values();

        if ($statuses->contains(SemiExpendableUsefulLife::STATUS_EXPIRED)) {
            return SemiExpendableUsefulLife::STATUS_EXPIRED;
        }

        if ($statuses->contains(SemiExpendableUsefulLife::STATUS_NEARING)) {
            return SemiExpendableUsefulLife::STATUS_NEARING;
        }

        if ($statuses->contains(SemiExpendableUsefulLife::STATUS_OK)) {
            return SemiExpendableUsefulLife::STATUS_OK;
        }

        return null;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertEmployeeOwnsPropertyItem(User $user, int $itemId, string $custodyTab): void
    {
        if (! self::isValidCustodyTab($custodyTab)) {
            $custodyTab = self::CUSTODY_TAB_ON_HAND;
        }

        $query = Issuance::query()
            ->where('issued_to', $user->id)
            ->where('item_id', $itemId);

        $this->applyPropertyCustodyTabFilter($query, $custodyTab);

        if (! $query->exists()) {
            throw new AuthorizationException('This item is not in your inventory.');
        }
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function presentPropertyIssuanceLedger(
        User $user,
        int $itemId,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        if (! self::isValidCustodyTab($custodyTab)) {
            $custodyTab = self::CUSTODY_TAB_ON_HAND;
        }

        $this->assertEmployeeOwnsPropertyItem($user, $itemId, $custodyTab);

        $issuances = $this->propertyIssuancesForItemQuery($user, $itemId, $custodyTab, $fromDate, $toDate)
            ->with([
                'item.category',
                'requisition.requestedBy',
                'requisition.endorsedBy',
                'requisition.sourceRequests.endorsedBy',
            ])
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get();

        $item = $issuances->first()?->item;
        $categorySlug = $item?->category?->getTemplateSlug();
        $icsParLabel = $categorySlug !== null
            ? OwwaReferenceLabels::issuanceControl($categorySlug)
            : 'ICS/PAR No.';

        $events = [];
        foreach ($issuances as $issuance) {
            $events[] = [
                'sort_date' => $issuance->issuance_date?->format('Y-m-d') ?? '',
                'sort_id' => (int) $issuance->id,
                'sort_order' => 0,
                'issuance_id' => $issuance->id,
                'row' => $this->buildPropertyIssuanceLedgerRow($user, $issuance, 'issued', $categorySlug, $custodyTab),
            ];

            if ($issuance->custody_ended_at !== null) {
                $endType = $issuance->custody_end_type === 'disposal' ? 'disposed' : 'returned';
                $events[] = [
                    'sort_date' => $issuance->custody_ended_at->format('Y-m-d'),
                    'sort_id' => (int) $issuance->id,
                    'sort_order' => 1,
                    'issuance_id' => $issuance->id,
                    'row' => $this->buildPropertyIssuanceLedgerRow($user, $issuance, $endType, $categorySlug, $custodyTab),
                ];
            }
        }

        usort($events, function (array $a, array $b): int {
            $dateCompare = strcmp($a['sort_date'], $b['sort_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            if ($a['sort_id'] !== $b['sort_id']) {
                return $a['sort_id'] <=> $b['sort_id'];
            }

            return $a['sort_order'] <=> $b['sort_order'];
        });

        $balance = 0;
        $rows = [];
        foreach ($events as $event) {
            $row = $event['row'];
            $balance += (int) ($row['quantity'] ?? 0);
            $row['balance'] = $balance;
            $rows[] = $row;
        }

        $rows = array_reverse($rows);
        $onHandBalance = max(0, $balance);

        $propertyLabel = OwwaReferenceLabels::assetIdentifierLabel($categorySlug ?? '');

        $columns = [
            'date' => $this->ledgerColumn(
                'Date Issued',
                OwwaReferenceLabels::propertyIssuanceDateLedgerTooltip($categorySlug),
            ),
            'ris_no' => $this->ledgerColumn(
                'RIS No.',
                'Requisition slip number that led to this issuance.',
            ),
            'ics_par_no' => $this->ledgerColumn(
                $icsParLabel,
                match ($categorySlug) {
                    'ppe' => 'Property Acknowledgment Receipt control number.',
                    'semi_expendable' => 'Inventory Custodian Slip control number.',
                    default => 'Inventory Custodian Slip or Property Acknowledgment Receipt control number.',
                },
            ),
            'property_number' => $this->ledgerColumn(
                $propertyLabel,
                OwwaReferenceLabels::propertyIdentifierLedgerTooltip($categorySlug),
            ),
            'quantity' => $this->ledgerColumn(
                'Qty received',
                'Quantity issued in this event.',
            ),
            'balance' => $this->ledgerColumn(
                'Balance',
                'Running total on hand after this event.',
            ),
            'distributed_by' => $this->ledgerColumn(
                'Distributed by',
                'Unit Consolidator who distributed this property to you.',
            ),
        ];

        if ($categorySlug === 'semi_expendable') {
            $columns['useful_life'] = $this->ledgerColumn(
                'Useful life',
                'Remaining estimated useful life for semi-expendable property.',
            );
        }

        if ($custodyTab === self::CUSTODY_TAB_ON_HAND && $categorySlug === 'semi_expendable') {
            $columns['action'] = $this->ledgerColumn(
                'Action',
                'Start a property return or other action for this unit.',
            );
        }

        return [
            'header' => [
                'item_name' => $item?->name ?? '—',
                'category_name' => $item?->category?->name ?? '—',
                'total_on_hand' => (string) $onHandBalance,
                'total_quantity' => (string) $issuances->sum(fn (Issuance $issuance): int => (int) ($issuance->quantity ?? 1)),
                'last_received' => $issuances->max('issuance_date')?->format('M j, Y') ?? '—',
                'distribution_count' => (string) $issuances->count(),
            ],
            'columns' => $columns,
            'rows' => $rows,
            'custody_tab' => $custodyTab,
            'category_slug' => $categorySlug,
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: LengthAwarePaginator
     * }
     */
    public function presentPropertyIssuanceLedgerPaginated(
        User $user,
        int $itemId,
        int $page = 1,
        int $perPage = 10,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $ledger = $this->presentPropertyIssuanceLedger($user, $itemId, $custodyTab, $fromDate, $toDate);
        $allRows = $ledger['rows'];
        $total = count($allRows);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($allRows, $offset, $perPage);

        $paginator = new Paginator(
            $pageRows,
            $total,
            $perPage,
            $page,
            ['pageName' => 'ledgerPage'],
        );

        return [
            'header' => $ledger['header'],
            'columns' => $ledger['columns'],
            'rows' => $pageRows,
            'paginator' => $paginator,
            'custody_tab' => $ledger['custody_tab'],
            'category_slug' => $ledger['category_slug'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPropertyIssuanceLedgerRow(
        User $employee,
        Issuance $issuance,
        string $eventType,
        ?string $categorySlug,
        string $custodyTab,
    ): array {
        $quantity = (int) ($issuance->quantity ?? 1);
        $eulStatus = SemiExpendableUsefulLife::statusForIssuance($issuance);
        $usefulLife = $issuance->estimated_useful_life
            ?? SemiExpendableUsefulLife::resolveForItem($issuance->item);

        $showPropertyAction = $categorySlug === 'semi_expendable'
            && $custodyTab === self::CUSTODY_TAB_ON_HAND
            && $eventType === 'issued'
            && in_array($eulStatus, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);

        $suggestedActionType = PropertyActionRequest::ACTION_DISPOSAL;
        if ($categorySlug === 'semi_expendable' && in_array($eulStatus, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
            $suggestedActionType = PropertyActionRequest::ACTION_REPLACEMENT;
        }

        if ($eventType === 'issued') {
            return [
                'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                'ris_no' => $issuance->requisition?->displayRisNumber()
                    ?? $issuance->requisition?->reference_code
                    ?? '—',
                'ics_par_no' => $issuance->controlNumber() ?? '—',
                'property_number' => $issuance->property_number ?? '—',
                'quantity' => $quantity,
                'distributed_by' => $this->resolveDistributedByName($employee, $issuance),
                'useful_life' => $usefulLife ?? '—',
                'action' => $showPropertyAction ? 'Start property action' : null,
                'property_action_url' => $showPropertyAction
                    ? PropertyActionRequestResource::createUrlForIssuance($issuance->id, $suggestedActionType)
                    : null,
            ];
        }

        $line = PropertyActionRequestLine::query()
            ->with(['transfer', 'disposal'])
            ->where('issuance_id', $issuance->id)
            ->latest('id')
            ->first();

        $reference = $eventType === 'disposed'
            ? ($issuance->custody_end_reference ?? $line?->disposal?->reference_code ?? '—')
            : collect([
                $issuance->custody_end_reference,
                $line?->transfer?->reference_code,
            ])->filter()->implode(' / ');

        if ($reference === '') {
            $reference = '—';
        }

        return [
            'date' => $issuance->custody_ended_at?->format('M j, Y') ?? '—',
            'ris_no' => '—',
            'ics_par_no' => $reference,
            'property_number' => $issuance->property_number ?? '—',
            'quantity' => -1 * $quantity,
            'distributed_by' => '—',
            'useful_life' => '—',
            'action' => null,
            'property_action_url' => null,
        ];
    }

    /**
     * @return array{label: string, tooltip?: string}|string
     */
    protected function ledgerColumn(string $label, ?string $tooltip = null): array|string
    {
        if ($tooltip === null) {
            return $label;
        }

        return [
            'label' => $label,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     custody_tab: string,
     *     category_slug: string|null
     * }
     */
    public function presentPropertyItemUnits(
        User $user,
        int $itemId,
        string $custodyTab = self::CUSTODY_TAB_ON_HAND,
        ?User $viewer = null,
    ): array {
        if (! self::isValidCustodyTab($custodyTab)) {
            $custodyTab = self::CUSTODY_TAB_ON_HAND;
        }

        $this->assertEmployeeOwnsPropertyItem($user, $itemId, $custodyTab);

        $issuances = $this->propertyIssuancesForItemQuery($user, $itemId, $custodyTab)
            ->with(['item.category'])
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get();

        $item = $issuances->first()?->item;
        $categorySlug = $item?->category?->getTemplateSlug();

        $rows = $issuances->map(function (Issuance $issuance) use ($categorySlug, $custodyTab, $viewer): array {
            $eulStatus = SemiExpendableUsefulLife::statusForIssuance($issuance);
            $usefulLife = $issuance->estimated_useful_life
                ?? SemiExpendableUsefulLife::resolveForItem($issuance->item);

            $showPropertyAction = $this->shouldShowPropertyActionForViewer(
                $viewer,
                $categorySlug,
                $custodyTab,
                $eulStatus,
            );

            $suggestedActionType = $this->suggestedPropertyActionTypeForViewer(
                $viewer,
                $categorySlug,
                $eulStatus,
            );

            return [
                'issuance_id' => $issuance->id,
                'property_number' => $issuance->property_number ?? '—',
                'issued_date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                'useful_life' => $usefulLife ?? '—',
                'expires_at' => $issuance->eul_expires_at?->format('M j, Y') ?? '—',
                'eul_status' => $eulStatus,
                'eul_status_label' => SemiExpendableUsefulLife::statusLabel($eulStatus),
                'show_property_action' => $showPropertyAction,
                'suggested_action_type' => $suggestedActionType,
                'property_action_url' => $showPropertyAction
                    ? PropertyActionRequestResource::createUrlForIssuance($issuance->id, $suggestedActionType)
                    : null,
            ];
        })->all();

        return [
            'header' => [
                'item_name' => $item?->name ?? '—',
                'category_name' => $item?->category?->name ?? '—',
                'total_quantity' => (string) $issuances->sum(fn (Issuance $issuance): int => (int) ($issuance->quantity ?? 1)),
            ],
            'columns' => [
                'property_number' => 'Property no.',
                'issued_date' => 'Issued',
                'useful_life' => 'Useful life',
                'expires_at' => 'Expires',
                'eul_status_label' => 'EUL status',
            ],
            'rows' => $rows,
            'custody_tab' => $custodyTab,
            'category_slug' => $categorySlug,
        ];
    }

    protected function enrichGroupedPropertyRows(
        LengthAwarePaginator $paginator,
        User $user,
        string $category,
        string $custodyTab,
    ): LengthAwarePaginator {
        $itemIds = collect($paginator->items())->pluck('item_id')->filter()->values();

        if ($itemIds->isEmpty()) {
            return $paginator;
        }

        $slug = $category === self::CATEGORY_PPE ? self::CATEGORY_PPE : self::CATEGORY_SEMI_EXPENDABLE;

        $issuancesByItem = Issuance::query()
            ->with(['item.category'])
            ->where('issued_to', $user->id)
            ->whereIn('item_id', $itemIds)
            ->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->whereIn(
                'item_category_id',
                $this->categoryIdsForSlug($slug),
            ))
            ->tap(fn (Builder $query) => $this->applyPropertyCustodyTabFilter($query, $custodyTab))
            ->get()
            ->groupBy('item_id');

        foreach ($paginator->items() as $row) {
            $itemIssuances = $issuancesByItem->get($row->item_id, collect());
            $row->template_slug = $itemIssuances->first()?->item?->category?->getTemplateSlug();
            $row->worst_eul_status = $this->worstEulStatusForIssuances($itemIssuances);
        }

        return $paginator;
    }

    /**
     * @return Builder<Issuance>
     */
    protected function propertyIssuancesForItemQuery(
        User $user,
        int $itemId,
        string $custodyTab,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        $query = Issuance::query()
            ->where('issued_to', $user->id)
            ->where('item_id', $itemId);

        $this->applyPropertyCustodyTabFilter($query, $custodyTab);
        $this->applyDatePeriodFilter($query, 'issuance_date', $fromDate, $toDate);

        return $query;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertEmployeeOwnsItem(User $user, int $itemId): void
    {
        $owns = Distribution::query()
            ->where('distributed_to', $user->id)
            ->where('item_id', $itemId)
            ->exists();

        if (! $owns) {
            throw new AuthorizationException('This item is not in your inventory.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertEmployeeOwnsIssuance(User $user, int $issuanceId): void
    {
        $owns = Issuance::query()
            ->whereKey($issuanceId)
            ->where('issued_to', $user->id)
            ->exists();

        if (! $owns) {
            throw new AuthorizationException('This property is not in your inventory.');
        }
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function presentPropertyCustodyLedger(User $user, int $issuanceId): array
    {
        $this->assertEmployeeOwnsIssuance($user, $issuanceId);

        $issuance = Issuance::query()
            ->with(['item.category', 'inventoryUnit'])
            ->findOrFail($issuanceId);

        $rows = [];
        $balance = 0;

        $rows[] = [
            'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
            'type' => 'Issued',
            'reference' => $issuance->controlNumber() ?? '—',
            'quantity' => (int) ($issuance->quantity ?? 1),
            'balance' => $balance = (int) ($issuance->quantity ?? 1),
            'remarks' => $issuance->remarks ?? '—',
        ];

        if ($issuance->custody_ended_at !== null) {
            $line = PropertyActionRequestLine::query()
                ->with(['transfer', 'disposal', 'propertyActionRequest'])
                ->where('issuance_id', $issuance->id)
                ->latest('id')
                ->first();

            if ($issuance->custody_end_type === 'return') {
                $balance = max(0, $balance - (int) ($issuance->quantity ?? 1));
                $rows[] = [
                    'date' => $issuance->custody_ended_at->format('M j, Y'),
                    'type' => 'Returned',
                    'reference' => collect([
                        $issuance->custody_end_reference,
                        $line?->transfer?->reference_code,
                    ])->filter()->implode(' / '),
                    'quantity' => -1 * (int) ($issuance->quantity ?? 1),
                    'balance' => $balance,
                    'remarks' => $line?->propertyActionRequest?->reason_detail
                        ?? $line?->transfer?->remarks
                        ?? '—',
                ];
            } elseif ($issuance->custody_end_type === 'disposal') {
                $balance = max(0, $balance - (int) ($issuance->quantity ?? 1));
                $rows[] = [
                    'date' => $issuance->custody_ended_at->format('M j, Y'),
                    'type' => 'Disposed',
                    'reference' => $issuance->custody_end_reference
                        ?? $line?->disposal?->reference_code
                        ?? '—',
                    'quantity' => -1 * (int) ($issuance->quantity ?? 1),
                    'balance' => $balance,
                    'remarks' => $line?->disposal?->reason ?? '—',
                ];
            }
        }

        return [
            'header' => [
                'item_name' => $issuance->item?->name ?? '—',
                'category_name' => $issuance->item?->category?->name ?? '—',
                'property_number' => $issuance->property_number ?? '—',
                'total_on_hand' => (string) max(0, $balance),
            ],
            'columns' => [
                'date' => 'Date',
                'type' => 'Type',
                'reference' => 'Reference',
                'quantity' => 'Qty',
                'balance' => 'Balance',
                'remarks' => 'Remarks',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: LengthAwarePaginator
     * }
     */
    public function presentPropertyCustodyLedgerPaginated(
        User $user,
        int $issuanceId,
        int $page = 1,
        int $perPage = 10,
    ): array {
        $ledger = $this->presentPropertyCustodyLedger($user, $issuanceId);
        $allRows = $ledger['rows'];
        $total = count($allRows);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($allRows, $offset, $perPage);

        $paginator = new Paginator(
            $pageRows,
            $total,
            $perPage,
            $page,
            ['pageName' => 'ledgerPage'],
        );

        return [
            'header' => $ledger['header'],
            'columns' => $ledger['columns'],
            'rows' => $pageRows,
            'paginator' => $paginator,
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function presentLedger(User $user, int $itemId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->assertEmployeeOwnsItem($user, $itemId);

        $query = Distribution::query()
            ->with([
                'requisition.endorsedBy',
                'requisition.requestedBy',
                'distributedBy',
                'item.category',
            ])
            ->where('distributed_to', $user->id)
            ->where('item_id', $itemId);
        $this->applyDatePeriodFilter($query, 'distribution_date', $fromDate, $toDate);

        $distributions = $query
            ->orderBy('distribution_date')
            ->orderBy('id')
            ->get();

        $item = $distributions->first()?->item;
        $balance = 0;
        $rows = [];

        foreach ($distributions as $distribution) {
            $balance += (int) $distribution->quantity;

            $rows[] = [
                'date' => $distribution->distribution_date?->format('M j, Y') ?? '—',
                'reference' => $distribution->requisition?->displayRisNumber()
                    ?? $distribution->requisition?->reference_code
                    ?? ('Distribution #'.$distribution->id),
                'quantity' => (int) $distribution->quantity,
                'balance' => $balance,
                'distributed_by' => $this->resolveDistributedByName($user, $distribution),
                'remarks' => $distribution->remarks ?? '—',
            ];
        }

        $rows = array_reverse($rows);

        return [
            'header' => [
                'item_name' => $item?->name ?? '—',
                'category_name' => $item?->category?->name ?? '—',
                'stock_no' => $item?->item_code,
                'total_on_hand' => (string) $balance,
                'total_quantity' => (string) $distributions->sum(fn (Distribution $distribution): int => (int) $distribution->quantity),
                'last_received' => $distributions->max('distribution_date')?->format('M j, Y') ?? '—',
                'distribution_count' => (string) $distributions->count(),
            ],
            'columns' => [
                'date' => $this->ledgerColumn(
                    'Date Issued',
                    'Date the Unit Consolidator distributed this item to you.',
                ),
                'reference' => $this->ledgerColumn(
                    'RIS No.',
                    'Requisition and Issue Slip number linked to this distribution.',
                ),
                'quantity' => $this->ledgerColumn(
                    'Qty received',
                    'Quantity received in this event.',
                ),
                'balance' => $this->ledgerColumn(
                    'Balance',
                    'Running total on hand after this event.',
                ),
                'distributed_by' => $this->ledgerColumn(
                    'Distributed by',
                    'Unit Consolidator who recorded the distribution.',
                ),
                'remarks' => $this->ledgerColumn(
                    'Remarks',
                    'Optional notes recorded with this distribution.',
                ),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: LengthAwarePaginator
     * }
     */
    public function presentLedgerPaginated(
        User $user,
        int $itemId,
        int $page = 1,
        int $perPage = 10,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $ledger = $this->presentLedger($user, $itemId, $fromDate, $toDate);
        $allRows = $ledger['rows'];
        $total = count($allRows);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($allRows, $offset, $perPage);

        $paginator = new Paginator(
            $pageRows,
            $total,
            $perPage,
            $page,
            ['pageName' => 'ledgerPage'],
        );

        return [
            'header' => $ledger['header'],
            'columns' => $ledger['columns'],
            'rows' => $pageRows,
            'paginator' => $paginator,
        ];
    }

    public function resolveDistributedByName(User $employee, Distribution|Issuance $record): string
    {
        if ($record instanceof Distribution) {
            return $this->resolveDistributedByFromDistribution($record)?->name ?? '—';
        }

        return $this->resolveDistributedByFromIssuance($employee, $record)?->name ?? '—';
    }

    protected function resolveDistributedByFromDistribution(Distribution $distribution): ?User
    {
        $distribution->loadMissing([
            'distributedBy',
            'requisition.endorsedBy',
            'requisition.requestedBy',
        ]);

        if ($distribution->distributedBy?->isUnitConsolidator()) {
            return $distribution->distributedBy;
        }

        if ($distribution->requisition?->endorsedBy?->isUnitConsolidator()) {
            return $distribution->requisition->endorsedBy;
        }

        return null;
    }

    protected function resolveDistributedByFromIssuance(User $employee, Issuance $issuance): ?User
    {
        $issuance->loadMissing([
            'requisition.requestedBy',
            'requisition.endorsedBy',
            'requisition.sourceRequests.endorsedBy',
        ]);

        $requisition = $issuance->requisition;
        if ($requisition === null) {
            return null;
        }

        if ($requisition->requestedBy?->isUnitConsolidator()) {
            return $requisition->requestedBy;
        }

        if ($requisition->endorsedBy?->isUnitConsolidator()) {
            return $requisition->endorsedBy;
        }

        foreach ($requisition->sourceRequests as $sourceRequest) {
            if ((int) $sourceRequest->requested_by === (int) $employee->id
                && $sourceRequest->endorsedBy?->isUnitConsolidator()) {
                return $sourceRequest->endorsedBy;
            }
        }

        return null;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertUnitConsolidatorCanViewEmployee(User $uc, User $employee): void
    {
        if (! $uc->isUnitConsolidator()) {
            throw new AuthorizationException('Only unit consolidators can view employee custody.');
        }

        if (! $employee->isEmployee()) {
            throw new AuthorizationException('Selected user is not an employee.');
        }

        if ($uc->office_id && (int) $employee->office_id !== (int) $uc->office_id) {
            throw new AuthorizationException('This employee is outside your office scope.');
        }

        if ($uc->department_id && (int) $employee->department_id !== (int) $uc->department_id) {
            throw new AuthorizationException('This employee is outside your department scope.');
        }
    }

    /**
     * @return array<int, string>
     */
    public function employeesInScopeForUnitConsolidator(User $uc): array
    {
        if (! $uc->isUnitConsolidator()) {
            return [];
        }

        $query = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->orderBy('name');

        if ($uc->office_id) {
            $query->where('office_id', $uc->office_id);
        }

        if ($uc->department_id) {
            $query->where('department_id', $uc->department_id);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function employeesForOfficeDepartment(User $uc, ?int $officeId, ?int $departmentId): array
    {
        if (! $uc->isUnitConsolidator()) {
            return [];
        }

        if ($officeId === null || $officeId <= 0 || $departmentId === null || $departmentId <= 0) {
            return [];
        }

        if (! $uc->coversOfficeDepartment($officeId, $departmentId)) {
            return [];
        }

        return User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('office_id', $officeId)
            ->where('department_id', $departmentId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function shouldShowPropertyActionForViewer(
        ?User $viewer,
        ?string $categorySlug,
        string $custodyTab,
        ?string $eulStatus,
    ): bool {
        if ($custodyTab !== self::CUSTODY_TAB_ON_HAND) {
            return false;
        }

        if ($viewer instanceof User && $viewer->isUnitConsolidator()) {
            return in_array($categorySlug, [self::CATEGORY_SEMI_EXPENDABLE, self::CATEGORY_PPE], true);
        }

        return $categorySlug === self::CATEGORY_SEMI_EXPENDABLE
            && in_array($eulStatus, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);
    }

    protected function suggestedPropertyActionTypeForViewer(
        ?User $viewer,
        ?string $categorySlug,
        ?string $eulStatus,
    ): string {
        if ($viewer instanceof User && $viewer->isUnitConsolidator()) {
            return PropertyActionRequest::ACTION_RETURN;
        }

        if ($categorySlug === self::CATEGORY_SEMI_EXPENDABLE
            && in_array($eulStatus, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
            return PropertyActionRequest::ACTION_REPLACEMENT;
        }

        return PropertyActionRequest::ACTION_DISPOSAL;
    }

    /**
     * @return Collection<int, int>
     */
    protected function applyPropertyCustodyTabFilter(Builder $query, string $custodyTab): void
    {
        if ($custodyTab === self::CUSTODY_TAB_HISTORY) {
            $query->whereNotNull('custody_ended_at');

            return;
        }

        $query
            ->whereNull('custody_ended_at')
            ->where(function (Builder $scope): void {
                $scope
                    ->whereDoesntHave('inventoryUnit')
                    ->orWhereHas('inventoryUnit', fn (Builder $unitQuery): Builder => $unitQuery
                        ->where('status', \App\Models\InventoryUnit::STATUS_ISSUED));
            });
    }

    protected function applyDatePeriodFilter(Builder $query, string $column, ?string $fromDate, ?string $toDate): void
    {
        $from = $this->parseDateFilter($fromDate)?->startOfDay();
        $to = $this->parseDateFilter($toDate)?->endOfDay();

        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    protected function parseDateFilter(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, int>
     */
    protected function categoryIdsForSlug(string $slug): Collection
    {
        return ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === $slug)
            ->pluck('id')
            ->values();
    }

    /**
     * @return Builder<\App\Models\Item>
     */
    protected function itemIdsForCategorySlug(string $slug): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Models\Item::query()
            ->select('id')
            ->whereIn('item_category_id', $this->categoryIdsForSlug($slug));
    }
}
