<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Transfer;
use App\Models\User;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

class OfficePropertyRegisterService
{
    public function __construct(
        protected OfficeDistributionBalanceService $balanceService,
    ) {}

    /**
     * @return Builder<Issuance>
     */
    public function queryForUser(User $user, ?int $categoryId = null): Builder
    {
        $query = Issuance::query()
            ->with(['item.category', 'office', 'department', 'issuedTo'])
            ->whereHas('item.category', function (Builder $categoryQuery): void {
                $categoryQuery->whereIn('name', $this->propertyCategoryNames());
            });

        if ($categoryId !== null && $categoryId > 0) {
            $query->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->where('item_category_id', $categoryId));
        }

        $this->applyOfficeScope($query, $user);

        return $query;
    }

    public function paginateStockCards(
        User $user,
        int $categoryId,
        ?string $search = null,
        string $sortBy = 'item_name',
        string $sortDir = 'asc',
        int $perPage = 10,
    ): LengthAwarePaginator {
        $officeId = (int) ($user->office_id ?? 0);

        if ($officeId <= 0) {
            return new Paginator([], 0, $perPage, 1);
        }

        $issuanceItemIds = Issuance::query()
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->where('item_category_id', $categoryId))
            ->distinct()
            ->pluck('item_id');

        $distributionItemIds = Distribution::query()
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->where('item_category_id', $categoryId))
            ->distinct()
            ->pluck('item_id');

        $itemIds = $issuanceItemIds->merge($distributionItemIds)->unique()->values();

        if ($itemIds->isEmpty()) {
            return new Paginator([], 0, $perPage, 1);
        }

        $query = Item::query()
            ->with('category')
            ->whereIn('id', $itemIds)
            ->where('item_category_id', $categoryId);

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $scope) use ($term): void {
                $scope->where('name', 'like', $term)
                    ->orWhereHas('category', fn (Builder $categoryQuery): Builder => $categoryQuery->where('name', 'like', $term));
            });
        }

        $items = $query->get()->map(function (Item $item) use ($officeId): object {
            $received = $this->balanceService->issuedQuantity((int) $item->id, $officeId);
            $issued = $this->balanceService->distributedQuantity((int) $item->id, $officeId);
            $balance = $this->balanceService->availableQuantity((int) $item->id, $officeId);

            return (object) [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'category_name' => $item->category?->name ?? '—',
                'received' => $received,
                'distributed' => $issued,
                'balance' => $balance,
            ];
        });

        $sorted = $items->sortBy(
            match ($sortBy) {
                'received' => 'received',
                'distributed', 'issued' => 'distributed',
                'balance' => 'balance',
                'category_name' => 'category_name',
                default => 'item_name',
            },
            SORT_REGULAR,
            $sortDir === 'desc',
        )->values();

        $page = max(1, (int) request()->query('page', 1));
        $offset = ($page - 1) * $perPage;
        $slice = $sorted->slice($offset, $perPage)->values();

        return new Paginator(
            $slice,
            $sorted->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function assertOfficeHasItem(User $user, int $itemId): void
    {
        $officeId = (int) ($user->office_id ?? 0);

        if ($officeId <= 0) {
            throw new AuthorizationException('Office scope is required.');
        }

        $hasIssuance = Issuance::query()
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->where('item_id', $itemId)
            ->exists();

        $hasDistribution = Distribution::query()
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->where('item_id', $itemId)
            ->exists();

        if (! $hasIssuance && ! $hasDistribution) {
            throw new AuthorizationException('This item is not in your office registry.');
        }
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     property_units: array<int, array<string, mixed>>,
     *     show_property_units: bool
     * }
     */
    public function presentOfficeStockLedger(User $user, int $itemId): array
    {
        $this->assertOfficeHasItem($user, $itemId);

        $officeId = (int) $user->office_id;
        $item = Item::query()->with('category')->findOrFail($itemId);
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';

        $issuances = Issuance::query()
            ->with(['requisition'])
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->where('item_id', $itemId)
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get();

        $distributions = Distribution::query()
            ->with(['requisition', 'distributedTo'])
            ->where('office_id', $officeId)
            ->when($user->department_id, fn (Builder $query): Builder => $query->where('department_id', $user->department_id))
            ->where('item_id', $itemId)
            ->orderBy('distribution_date')
            ->orderBy('id')
            ->get();

        $events = collect();

        foreach ($issuances as $issuance) {
            $issuance->loadMissing(['issuedTo', 'consolidatedRequisition.requestedBy']);
            $reference = $issuance->controlNumber() ?? 'Issuance #'.$issuance->id;
            if (filled($issuance->property_number)) {
                $reference = trim($issuance->property_number.' — '.$reference);
            }

            if ($issuance->isEmployeeDirectIssuance()) {
                $employee = $issuance->issuedTo?->name ?? '—';
                $uc = $issuance->consolidatedRequisition?->requestedBy?->name;
                $employeeLabel = filled($uc) ? "{$employee} (via {$uc})" : $employee;

                $events->push([
                    'sort_date' => $issuance->issuance_date?->format('Y-m-d') ?? '0000-01-01',
                    'sort_id' => $issuance->id,
                    'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                    'reference' => $issuance->consolidatedRequisition?->reference_code ?? $reference,
                    'employee' => $employeeLabel,
                    'type' => 'Issued',
                    'quantity' => (int) $issuance->quantity,
                    'direction' => 0,
                ]);

                continue;
            }

            $events->push([
                'sort_date' => $issuance->issuance_date?->format('Y-m-d') ?? '0000-01-01',
                'sort_id' => $issuance->id,
                'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                'reference' => $reference,
                'employee' => '—',
                'type' => 'Received',
                'quantity' => (int) $issuance->quantity,
                'direction' => 1,
            ]);
        }

        foreach ($distributions as $distribution) {
            $reference = $distribution->requisition?->displayTransactionNumber()
                ?? $distribution->requisition?->reference_code
                ?? ('Distribution #'.$distribution->id);

            $events->push([
                'sort_date' => $distribution->distribution_date?->format('Y-m-d') ?? '0000-01-01',
                'sort_id' => $distribution->id,
                'date' => $distribution->distribution_date?->format('M j, Y') ?? '—',
                'reference' => $reference,
                'employee' => $distribution->distributedTo?->name ?? '—',
                'type' => 'Distributed',
                'quantity' => (int) $distribution->quantity,
                'direction' => -1,
            ]);
        }

        $events = $events->sortBy([
            ['sort_date', 'asc'],
            ['sort_id', 'asc'],
        ])->values();

        $balance = 0;
        $rows = [];

        foreach ($events as $event) {
            $balance += $event['direction'] * $event['quantity'];

            $rows[] = [
                'date' => $event['date'],
                'reference' => $event['reference'],
                'employee' => $event['employee'],
                'type' => $event['type'],
                'quantity' => $event['quantity'],
                'balance' => $balance,
            ];
        }

        $rows = array_reverse($rows);

        return [
            'header' => [
                'item_name' => $item->name,
                'category_name' => $item->category?->name ?? '—',
                'total_on_hand' => (string) $this->balanceService->availableQuantity($itemId, $officeId),
            ],
            'columns' => [
                'date' => 'Date',
                'reference' => 'Reference',
                'employee' => 'Employee',
                'type' => 'Type',
                'quantity' => 'Qty',
                'balance' => 'Balance',
            ],
            'rows' => $rows,
            'property_units' => InventoryCategoryOptions::isPropertyCategorySlug($slug)
                ? $this->presentPropertyUnitsForItem($user, $itemId)
                : [],
            'show_property_units' => InventoryCategoryOptions::isPropertyCategorySlug($slug),
        ];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     property_units: array<int, array<string, mixed>>,
     *     show_property_units: bool,
     *     paginator: LengthAwarePaginator
     * }
     */
    public function presentOfficeStockLedgerPaginated(User $user, int $itemId, int $page = 1, int $perPage = 10): array
    {
        $ledger = $this->presentOfficeStockLedger($user, $itemId);
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
            ...$ledger,
            'rows' => $pageRows,
            'paginator' => $paginator,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presentPropertyUnitsForItem(User $user, int $itemId): array
    {
        return $this->queryForUser($user)
            ->where('item_id', $itemId)
            ->orderByDesc('issuance_date')
            ->get()
            ->map(function (Issuance $issuance): array {
                $slug = $issuance->item?->category?->getTemplateSlug();
                $eulStatus = $slug === 'semi_expendable'
                    ? SemiExpendableUsefulLife::statusForIssuance($issuance)
                    : null;

                return [
                    'issuance_id' => $issuance->id,
                    'property_number' => $issuance->property_number ?? '—',
                    'reference_code' => $issuance->controlNumber() ?? '—',
                    'issued_to' => $issuance->issuedTo?->name ?? '—',
                    'issuance_date' => $issuance->issuance_date?->format('M d, Y') ?? '—',
                    'eul_status' => $eulStatus,
                    'category_slug' => $slug,
                ];
            })
            ->all();
    }

    public function countNearingExpiryForUser(User $user): int
    {
        return count($this->listNearingExpiryForUser($user));
    }

    /**
     * @return array<int, array{property_number: string|null, item: string|null, category: string|null, issued_to: string|null, expires_at: string|null, status: string}>
     */
    public function listNearingExpiryForUser(User $user, int $limit = 100): array
    {
        return $this->queryForUser($user)
            ->whereNotNull('eul_expires_at')
            ->orderBy('eul_expires_at')
            ->get()
            ->filter(function (Issuance $issuance): bool {
                $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

                return in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);
            })
            ->take($limit)
            ->map(function (Issuance $issuance): array {
                $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

                return [
                    'property_number' => $issuance->property_number,
                    'item' => $issuance->item?->name,
                    'category' => $issuance->item?->category?->name,
                    'issued_to' => $issuance->issuedTo?->name,
                    'expires_at' => $issuance->eul_expires_at?->format('M j, Y'),
                    'status' => SemiExpendableUsefulLife::statusLabel($status),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function propertyCategoryNames(): array
    {
        return \App\Models\ItemCategory::query()
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (\App\Models\ItemCategory $category): bool => InventoryCategoryOptions::isPropertyCategorySlug($category->getTemplateSlug()))
            ->pluck('name')
            ->all();
    }

    public function paginateTransfersForUser(
        User $user,
        ?int $categoryId = null,
        string $direction = 'all',
        ?string $search = null,
        int $perPage = 10,
    ): LengthAwarePaginator {
        $officeId = (int) ($user->office_id ?? 0);

        if ($officeId <= 0 || ! $user->isUnitConsolidator()) {
            return new Paginator([], 0, $perPage, 1);
        }

        $propertyCategoryNames = $this->propertyCategoryNames();

        $query = Transfer::query()
            ->with(['item.category', 'fromOffice', 'toOffice'])
            ->where(function (Builder $scope) use ($officeId): void {
                $scope->where('from_office_id', $officeId)
                    ->orWhere('to_office_id', $officeId);
            })
            ->whereHas('item.category', function (Builder $categoryQuery) use ($propertyCategoryNames): void {
                $categoryQuery->whereIn('name', $propertyCategoryNames);
            })
            ->when(
                $categoryId !== null && $categoryId > 0,
                fn (Builder $q): Builder => $q->whereHas(
                    'item',
                    fn (Builder $itemQuery): Builder => $itemQuery->where('item_category_id', $categoryId),
                ),
            );

        if ($direction === 'incoming') {
            $query->where('to_office_id', $officeId);
        } elseif ($direction === 'outgoing') {
            $query->where('from_office_id', $officeId);
        }

        if (filled($search)) {
            $term = '%'.trim($search).'%';
            $query->where(function (Builder $scope) use ($term): void {
                $scope->where('reference_code', 'like', $term)
                    ->orWhere('property_number', 'like', $term)
                    ->orWhereHas('item', fn (Builder $itemQuery): Builder => $itemQuery->where('name', 'like', $term))
                    ->orWhereHas('fromOffice', fn (Builder $officeQuery): Builder => $officeQuery->where('name', 'like', $term))
                    ->orWhereHas('toOffice', fn (Builder $officeQuery): Builder => $officeQuery->where('name', 'like', $term));
            });
        }

        return $query
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(function (Transfer $transfer) use ($officeId): object {
                $directionLabel = (int) $transfer->to_office_id === $officeId
                    ? 'Incoming'
                    : 'Outgoing';

                return (object) [
                    'id' => $transfer->id,
                    'reference_code' => $transfer->reference_code,
                    'transfer_date' => $transfer->transfer_date,
                    'item_name' => $transfer->item?->name ?? '—',
                    'quantity' => (int) $transfer->quantity,
                    'from_office_name' => $transfer->fromOffice?->name ?? '—',
                    'to_office_name' => $transfer->toOffice?->name ?? '—',
                    'direction' => $directionLabel,
                    'property_number' => $transfer->property_number,
                ];
            });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function presentTransferForUser(User $user, int $transferId): array
    {
        $officeId = (int) ($user->office_id ?? 0);

        if ($officeId <= 0 || ! $user->isUnitConsolidator()) {
            throw new AuthorizationException;
        }

        $transfer = Transfer::query()
            ->with(['item.category', 'fromOffice', 'toOffice'])
            ->whereKey($transferId)
            ->where(function (Builder $scope) use ($officeId): void {
                $scope->where('from_office_id', $officeId)
                    ->orWhere('to_office_id', $officeId);
            })
            ->first();

        if ($transfer === null) {
            throw new AuthorizationException;
        }

        $direction = (int) $transfer->to_office_id === $officeId ? 'Incoming' : 'Outgoing';
        $identifierLabel = OwwaReferenceLabels::assetIdentifierLabel(
            $transfer->item?->category?->getTemplateSlug()
        );
        $identifier = OwwaReferenceLabels::assetIdentifierForTransfer($transfer);

        return [
            'reference_code' => $transfer->reference_code,
            'direction' => $direction,
            'item_name' => $transfer->item?->name ?? '—',
            'quantity' => (int) $transfer->quantity,
            'transfer_date' => $transfer->transfer_date?->format('M d, Y') ?? '—',
            'from_office_name' => $transfer->fromOffice?->name ?? '—',
            'to_office_name' => $transfer->toOffice?->name ?? '—',
            'identifier_label' => $identifierLabel,
            'identifier' => filled($identifier) ? $identifier : '—',
            'condition' => filled($transfer->condition) ? $transfer->condition : '—',
            'remarks' => filled($transfer->remarks) ? $transfer->remarks : '—',
            'from_accountable_officer' => filled($transfer->from_accountable_officer) ? $transfer->from_accountable_officer : '—',
            'to_accountable_officer' => filled($transfer->to_accountable_officer) ? $transfer->to_accountable_officer : '—',
        ];
    }

    protected function applyOfficeScope(Builder $query, User $user): void
    {
        if (! $user->isUnitConsolidator()) {
            return;
        }

        $query->where(function (Builder $scope) use ($user): void {
            $scope->where('issued_to', $user->id);

            if ($user->office_id) {
                $scope->orWhere(function (Builder $officeScope) use ($user): void {
                    $officeScope->where('office_id', $user->office_id);

                    if ($user->department_id) {
                        $officeScope->where('department_id', $user->department_id);
                    }
                });
            }
        });
    }
}
