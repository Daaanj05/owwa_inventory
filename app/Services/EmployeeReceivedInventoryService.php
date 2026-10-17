<?php

namespace App\Services;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\InventoryUnit;
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

class EmployeeReceivedInventoryService
{
    public const CATEGORY_CONSUMABLES = 'consumables';

    public const CATEGORY_SEMI_EXPENDABLE = 'semi_expendable';

    public const CATEGORY_PPE = 'ppe';

    public const CUSTODY_TAB_ON_HAND = 'on_hand';

    public const CUSTODY_TAB_HISTORY = 'history';

    private const EXECUTED_RETURN_LINE_RELATIONS = [
        'executedReturnLines.propertyActionRequest',
        'executedReturnLines.inventoryUnit',
        'executedReturnLines.transfer',
        'executedReturnLines.disposal',
    ];

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
            $quantityExpression = DB::raw($this->propertyQuantityExpression($custodyTab, 'issuances'));

            return [
                'totalItems' => (int) (clone $base)->distinct('item_id')->count('item_id'),
                'totalQuantity' => (int) (clone $base)->sum($quantityExpression),
                'totalQuantityThisYear' => (int) (clone $base)
                    ->whereBetween('issuance_date', [now()->startOfYear(), now()->endOfYear()])
                    ->sum($quantityExpression),
            ];
        }

        $base = Issuance::query()
            ->where('issued_to', $user->id)
            ->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->whereIn(
                'item_category_id',
                $this->categoryIdsForSlug($category),
            ));

        $this->applyDatePeriodFilter($base, 'issuance_date', $fromDate, $toDate);

        return [
            'totalItems' => (int) (clone $base)->distinct('item_id')->count('item_id'),
            'totalQuantity' => (int) (clone $base)->sum('quantity'),
            'totalQuantityThisYear' => (int) (clone $base)
                ->whereBetween('issuance_date', [now()->startOfYear(), now()->endOfYear()])
                ->sum('quantity'),
        ];
    }

    /**
     * @return Builder<Issuance>
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

        $query = Issuance::query()
            ->select([
                'issuances.item_id',
                DB::raw('SUM(issuances.quantity) as total_quantity'),
                DB::raw('MAX(issuances.issuance_date) as last_received_date'),
                DB::raw('COUNT(*) as receipt_count'),
            ])
            ->join('items', 'items.id', '=', 'issuances.item_id')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->where('issuances.issued_to', $user->id)
            ->whereIn('items.item_category_id', $this->categoryIdsForSlug($category))
            ->groupBy('issuances.item_id', 'items.name', 'item_categories.name')
            ->addSelect([
                'items.name as item_name',
                'item_categories.name as category_name',
            ]);
        $this->applyDatePeriodFilter($query, 'issuances.issuance_date', $fromDate, $toDate);

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
            'received_date' => 'last_received_date',
            'receipt_count' => 'receipt_count',
            default => 'last_received_date',
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
                DB::raw('SUM('.$this->propertyQuantityExpression($custodyTab, 'issuances').') as total_quantity'),
                DB::raw('COUNT(*) as receipt_count'),
                DB::raw('MAX(issuances.issuance_date) as last_received_date'),
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
            'received_date', 'last_received_date', 'issuance_date', 'last_issuance_date' => 'last_received_date',
            'receipt_count' => 'receipt_count',
            default => 'last_received_date',
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
                ...self::EXECUTED_RETURN_LINE_RELATIONS,
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
                'row' => $this->buildPropertyIssuanceLedgerRow($user, $issuance, $categorySlug, $custodyTab),
            ];

            foreach ($this->custodyEndRows($issuance) as $index => $endRow) {
                $events[] = [
                    'sort_date' => $endRow['sort_date'],
                    'sort_id' => (int) $issuance->id,
                    'sort_order' => 1 + $index,
                    'issuance_id' => $issuance->id,
                    'row' => [
                        'date' => $endRow['date'],
                        'ris_no' => '—',
                        'ics_par_no' => $endRow['reference'],
                        'property_number' => $endRow['property_number'],
                        'quantity' => $endRow['quantity'],
                        'endorsed_by' => '—',
                        'useful_life' => '—',
                        'action' => null,
                        'property_action_url' => null,
                    ],
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
            'endorsed_by' => $this->ledgerColumn(
                'Endorsed by',
                'Unit Consolidator who endorsed your request.',
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
                'receipt_count' => (string) $issuances->count(),
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
        ?string $categorySlug,
        string $custodyTab,
    ): array {
        $eulStatus = SemiExpendableUsefulLife::statusForIssuance($issuance);
        $usefulLife = $issuance->estimated_useful_life
            ?? SemiExpendableUsefulLife::resolveForItem($issuance->item);

        $showPropertyAction = $categorySlug === 'semi_expendable'
            && $custodyTab === self::CUSTODY_TAB_ON_HAND
            && in_array($eulStatus, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);

        $suggestedActionType = $showPropertyAction
            ? PropertyActionRequest::ACTION_REPLACEMENT
            : PropertyActionRequest::ACTION_DISPOSAL;

        return [
            'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
            'ris_no' => $issuance->requisition?->displayRisNumber()
                ?? $issuance->requisition?->reference_code
                ?? '—',
            'ics_par_no' => $issuance->controlNumber() ?? '—',
            'property_number' => $issuance->issuedPropertyNumbersLabel() ?: '—',
            'quantity' => (int) ($issuance->quantity ?? 1),
            'endorsed_by' => $this->resolveEndorsedByName($employee, $issuance),
            'useful_life' => $usefulLife ?? '—',
            'issuance_id' => $issuance->id,
            'show_useful_life_report' => $showPropertyAction,
            'useful_life_condition' => $issuance->useful_life_condition,
            'action' => $showPropertyAction ? 'Needs replacement' : null,
            'property_action_url' => $showPropertyAction
                ? PropertyActionRequestResource::createUrlForIssuance($issuance->id, $suggestedActionType)
                : null,
        ];
    }

    /**
     * One ledger row per executed return line, labelled by the Supply Custodian's receive outcome.
     *
     * @return list<array{sort_date: string, date: string, type: string, reference: string, property_number: string, quantity: int, remarks: string}>
     */
    protected function custodyEndRows(Issuance $issuance): array
    {
        $issuance->loadMissing(self::EXECUTED_RETURN_LINE_RELATIONS);

        return $issuance->executedReturnLines
            ->map(function (PropertyActionRequestLine $line) use ($issuance): array {
                $request = $line->propertyActionRequest;
                $endedAt = $request?->executed_at ?? $issuance->custody_ended_at ?? $line->updated_at;
                $isDisposal = $line->outcome === PropertyActionRequest::OUTCOME_DISPOSE;
                $reference = collect([
                    $request?->reference_code,
                    $isDisposal ? $line->disposal?->reference_code : $line->transfer?->reference_code,
                ])->filter()->implode(' / ');

                return [
                    'sort_date' => $endedAt?->format('Y-m-d') ?? '',
                    'date' => $endedAt?->format('M j, Y') ?? '—',
                    'type' => match ($line->outcome) {
                        PropertyActionRequest::OUTCOME_DISPOSE => 'Disposed',
                        PropertyActionRequest::OUTCOME_REPAIR => 'Returned for repair',
                        default => 'Returned',
                    },
                    'reference' => $reference !== '' ? $reference : '—',
                    'property_number' => $line->inventoryUnit?->property_number ?? $issuance->issuedPropertyNumbersLabel() ?: '—',
                    'quantity' => -1 * ($line->inventory_unit_id !== null ? 1 : max(1, (int) $line->quantity)),
                    'remarks' => $isDisposal
                        ? ($line->disposal?->reason ?? $request?->reasonLabel() ?? '—')
                        : ($request?->reasonLabel() ?? $line->transfer?->remarks ?? '—'),
                ];
            })
            ->values()
            ->all();
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
            ->with(['item.category', 'inventoryUnits'])
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

            $heldNumbers = $issuance->inventoryUnits
                ->where('status', InventoryUnit::STATUS_ISSUED)
                ->pluck('property_number')
                ->filter()
                ->implode(', ');

            return [
                'issuance_id' => $issuance->id,
                'property_number' => $custodyTab === self::CUSTODY_TAB_ON_HAND && $heldNumbers !== ''
                    ? $heldNumbers
                    : ($issuance->issuedPropertyNumbersLabel() ?: '—'),
                'issued_date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                'useful_life' => $usefulLife ?? '—',
                'expires_at' => $issuance->eul_expires_at?->format('M j, Y') ?? '—',
                'eul_status' => $eulStatus,
                'eul_status_label' => SemiExpendableUsefulLife::statusLabel($eulStatus),
                'show_property_action' => $showPropertyAction,
                'show_useful_life_report' => $showPropertyAction
                    && $categorySlug === self::CATEGORY_SEMI_EXPENDABLE
                    && ! ($viewer instanceof User && $viewer->isUnitConsolidator()),
                'useful_life_condition' => $issuance->useful_life_condition,
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
                'total_quantity' => (string) $issuances->sum(fn (Issuance $issuance): int => $custodyTab === self::CUSTODY_TAB_ON_HAND
                    ? $issuance->remainingQuantity()
                    : (int) ($issuance->quantity ?? 1)),
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
        $owns = Issuance::query()
            ->where('issued_to', $user->id)
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
            ->with(['item.category', ...self::EXECUTED_RETURN_LINE_RELATIONS])
            ->findOrFail($issuanceId);

        $balance = (int) ($issuance->quantity ?? 1);
        $rows = [[
            'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
            'type' => 'Issued',
            'reference' => $issuance->controlNumber() ?? '—',
            'quantity' => $balance,
            'balance' => $balance,
            'remarks' => $issuance->remarks ?? '—',
        ]];

        foreach ($this->custodyEndRows($issuance) as $endRow) {
            $balance = max(0, $balance + $endRow['quantity']);
            $rows[] = [
                'date' => $endRow['date'],
                'type' => $endRow['type'].($endRow['property_number'] !== '—' ? ' ('.$endRow['property_number'].')' : ''),
                'reference' => $endRow['reference'],
                'quantity' => $endRow['quantity'],
                'balance' => $balance,
                'remarks' => $endRow['remarks'],
            ];
        }

        return [
            'header' => [
                'item_name' => $issuance->item?->name ?? '—',
                'category_name' => $issuance->item?->category?->name ?? '—',
                'property_number' => $issuance->issuedPropertyNumbersLabel() ?: '—',
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

        $query = Issuance::query()
            ->with([
                'requisition.endorsedBy',
                'requisition.requestedBy',
                'consolidatedRequisition.requestedBy',
                'issuedBy',
                'item.category',
            ])
            ->where('issued_to', $user->id)
            ->where('item_id', $itemId);
        $this->applyDatePeriodFilter($query, 'issuance_date', $fromDate, $toDate);

        $issuances = $query
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get();

        $item = $issuances->first()?->item;
        $balance = 0;
        $rows = [];

        foreach ($issuances as $issuance) {
            $balance += (int) $issuance->quantity;

            $rows[] = [
                'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                'reference' => $issuance->consolidatedRequisition?->reference_code
                    ?? $issuance->requisition?->displayRisNumber()
                    ?? $issuance->requisition?->reference_code
                    ?? $issuance->controlNumber()
                    ?? ('Issuance #'.$issuance->id),
                'quantity' => (int) $issuance->quantity,
                'balance' => $balance,
                'endorsed_by' => $this->resolveEndorsedByName($user, $issuance),
                'remarks' => $issuance->remarks ?? '—',
            ];
        }

        $rows = array_reverse($rows);

        return [
            'header' => [
                'item_name' => $item?->name ?? '—',
                'category_name' => $item?->category?->name ?? '—',
                'stock_no' => $item?->item_code,
                'total_on_hand' => (string) $balance,
                'total_quantity' => (string) $issuances->sum(fn (Issuance $issuance): int => (int) $issuance->quantity),
                'last_received' => $issuances->max('issuance_date')?->format('M j, Y') ?? '—',
                'receipt_count' => (string) $issuances->count(),
            ],
            'columns' => [
                'date' => $this->ledgerColumn(
                    'Date Issued',
                    'Date the Supply Custodian issued this item to you.',
                ),
                'reference' => $this->ledgerColumn(
                    'RIS No.',
                    'Requisition and Issue Slip number linked to this issuance.',
                ),
                'quantity' => $this->ledgerColumn(
                    'Qty received',
                    'Quantity received in this event.',
                ),
                'balance' => $this->ledgerColumn(
                    'Balance',
                    'Running total on hand after this event.',
                ),
                'endorsed_by' => $this->ledgerColumn(
                    'Endorsed by',
                    'Unit Consolidator who endorsed your request.',
                ),
                'remarks' => $this->ledgerColumn(
                    'Remarks',
                    'Optional notes recorded with this issuance.',
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

    public function resolveEndorsedByName(User $employee, Issuance $issuance): string
    {
        return $this->resolveEndorsingConsolidator($employee, $issuance)?->name ?? '—';
    }

    protected function resolveEndorsingConsolidator(User $employee, Issuance $issuance): ?User
    {
        $issuance->loadMissing([
            'consolidatedRequisition.requestedBy',
            'requisition.requestedBy',
            'requisition.endorsedBy',
            'requisition.sourceRequests.endorsedBy',
        ]);

        if ($issuance->consolidatedRequisition?->requestedBy?->isUnitConsolidator()) {
            return $issuance->consolidatedRequisition->requestedBy;
        }

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

        $employeeOfficeId = (int) ($employee->office_id ?? 0);
        $employeeDepartmentId = (int) ($employee->department_id ?? 0);

        if ($uc->assignments()->exists()) {
            if ($employeeOfficeId <= 0
                || $employeeDepartmentId <= 0
                || ! $uc->coversOfficeDepartment($employeeOfficeId, $employeeDepartmentId)) {
                throw new AuthorizationException('This employee is outside your assignment scope.');
            }

            return;
        }

        if ($uc->office_id && $employeeOfficeId !== (int) $uc->office_id) {
            throw new AuthorizationException('This employee is outside your office scope.');
        }

        if ($uc->department_id && $employeeDepartmentId !== (int) $uc->department_id) {
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

        $hasAssignments = $uc->assignments()->exists();

        if ($hasAssignments && ! $uc->coversOfficeDepartment($officeId, $departmentId)) {
            return [];
        }

        if (! $hasAssignments
            && ((int) ($uc->office_id ?? 0) !== $officeId
                || (int) ($uc->department_id ?? 0) !== $departmentId)) {
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
                    ->whereDoesntHave('inventoryUnits')
                    ->orWhereHas('inventoryUnits', fn (Builder $unitQuery): Builder => $unitQuery
                        ->where('status', InventoryUnit::STATUS_ISSUED));
            });
    }

    /**
     * On-hand counts only what the employee still holds; history counts what was originally issued.
     */
    protected function propertyQuantityExpression(string $custodyTab, string $table): string
    {
        return $custodyTab === self::CUSTODY_TAB_HISTORY
            ? "{$table}.quantity"
            : "({$table}.quantity - {$table}.returned_quantity)";
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
}
