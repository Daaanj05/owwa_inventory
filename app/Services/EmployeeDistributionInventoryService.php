<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeDistributionInventoryService
{
    public const CATEGORY_CONSUMABLES = 'consumables';

    public const CATEGORY_SEMI_EXPENDABLE = 'semi_expendable';

    public const CATEGORY_PPE = 'ppe';

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

    /**
     * @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int}
     */
    public function summaryFor(User $user, string $category = self::CATEGORY_CONSUMABLES): array
    {
        if (! self::isValidCategory($category)) {
            $category = self::CATEGORY_CONSUMABLES;
        }

        $base = Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereIn('item_id', $this->itemIdsForCategorySlug($category));

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
    ): LengthAwarePaginator {
        $query = $this->groupedInventoryQuery($user, $search, $category);

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
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function presentLedger(User $user, int $itemId): array
    {
        $this->assertEmployeeOwnsItem($user, $itemId);

        $distributions = Distribution::query()
            ->with(['requisition', 'distributedBy', 'item.category'])
            ->where('distributed_to', $user->id)
            ->where('item_id', $itemId)
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
                'reference' => $distribution->requisition?->reference_code
                    ?? ('Distribution #'.$distribution->id),
                'quantity' => (int) $distribution->quantity,
                'balance' => $balance,
                'distributed_by' => $distribution->distributedBy?->name ?? '—',
                'remarks' => $distribution->remarks ?? '—',
            ];
        }

        $rows = array_reverse($rows);

        return [
            'header' => [
                'item_name' => $item?->name ?? '—',
                'category_name' => $item?->category?->name ?? '—',
                'total_on_hand' => (string) $balance,
            ],
            'columns' => [
                'date' => 'Date',
                'reference' => 'Reference',
                'quantity' => 'Qty received',
                'balance' => 'Balance',
                'distributed_by' => 'Distributed by',
                'remarks' => 'Remarks',
            ],
            'rows' => $rows,
        ];
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
