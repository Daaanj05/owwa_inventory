<?php

namespace App\Services;

use App\Models\ItemCategory;
use App\Support\UnitCostKey;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockLevelExportService
{
    public const int MAX_PAIRS = 100;

    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    public function encodePairKey(int $itemId, int $officeId, ?float $unitCost = null): string
    {
        if ($unitCost !== null) {
            return $itemId.':'.$officeId.':'.UnitCostKey::normalize($unitCost);
        }

        return $itemId.':'.$officeId;
    }

    /**
     * @return array{item_id: int, office_id: int, unit_cost: float|null}|null
     */
    public function decodePairKey(string $key): ?array
    {
        $parts = explode(':', trim($key), 3);
        if (count($parts) < 2) {
            return null;
        }

        $itemId = (int) ($parts[0] ?? 0);
        $officeId = (int) ($parts[1] ?? 0);

        if ($itemId <= 0 || $officeId <= 0) {
            return null;
        }

        $unitCost = isset($parts[2]) && $parts[2] !== ''
            ? (float) $parts[2]
            : null;

        return [
            'item_id' => $itemId,
            'office_id' => $officeId,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function pairKeysFromRequest(Request $request): array
    {
        $pairs = $request->query('pairs');

        if ($pairs === null || $pairs === '' || $pairs === []) {
            return [];
        }

        if (is_string($pairs)) {
            return array_values(array_filter(array_map('trim', explode(',', $pairs))));
        }

        if (is_array($pairs)) {
            return array_values(array_filter(array_map(
                fn (mixed $value): string => trim((string) $value),
                $pairs,
            )));
        }

        return [];
    }

    /**
     * @return Collection<int, object>
     */
    public function filterStockLevelRows(
        ?int $categoryId,
        ?string $search,
        string $restockFilter = 'active',
        ?int $scopedOfficeId = null,
    ): Collection {
        $rows = $this->stockService->getStockLevelsList();

        if ($scopedOfficeId !== null && $scopedOfficeId > 0) {
            $rows = $rows->where('office_id', $scopedOfficeId)->values();
        }

        if ($categoryId !== null && $categoryId > 0) {
            $category = ItemCategory::query()->find($categoryId);
            if ($category !== null) {
                $rows = $rows->where('category_name', $category->name)->values();
            }
        }

        if (filled($search)) {
            $term = mb_strtolower($search);
            $rows = $rows->filter(fn (object $row): bool => str_contains(mb_strtolower($row->item_name ?? ''), $term)
                || str_contains(mb_strtolower($row->office_name ?? ''), $term)
            )->values();
        }

        $restockFilter = in_array($restockFilter, ['active', 'inactive'], true) ? $restockFilter : 'active';

        return $rows->filter(function (object $row) use ($restockFilter): bool {
            $isInactive = (bool) ($row->is_inactive_for_restock ?? false);

            return $restockFilter === 'inactive' ? $isInactive : ! $isInactive;
        })->values();
    }

    /**
     * @param  array<int, string>  $explicitPairKeys
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    public function resolvePairs(
        ?int $categoryId,
        ?string $search,
        string $restockFilter,
        ?int $scopedOfficeId,
        array $explicitPairKeys = [],
    ): Collection {
        $visibleRows = $this->filterStockLevelRows($categoryId, $search, $restockFilter, $scopedOfficeId);

        if ($explicitPairKeys !== []) {
            $resolved = collect();

            foreach ($explicitPairKeys as $pairKey) {
                $decoded = $this->decodePairKey($pairKey);
                if ($decoded === null) {
                    continue;
                }

                $match = $visibleRows->first(
                    fn (object $row): bool => (int) ($row->item_id ?? 0) === $decoded['item_id']
                        && (int) ($row->office_id ?? 0) === $decoded['office_id']
                        && ($decoded['unit_cost'] === null || UnitCostKey::equals(
                            isset($row->unit_cost) ? (float) $row->unit_cost : null,
                            $decoded['unit_cost'],
                        )),
                );

                if ($match === null) {
                    continue;
                }

                $resolved->push([
                    'item_id' => $decoded['item_id'],
                    'office_id' => $decoded['office_id'],
                    'unit_cost' => $decoded['unit_cost'] ?? (isset($match->unit_cost) ? (float) $match->unit_cost : null),
                ]);
            }

            $pairs = $resolved->unique(fn (array $pair): string => $this->encodePairKey(
                $pair['item_id'],
                $pair['office_id'],
                $pair['unit_cost'],
            ))->values();
        } else {
            $pairs = $visibleRows->map(fn (object $row): array => [
                'item_id' => (int) $row->item_id,
                'office_id' => (int) $row->office_id,
                'unit_cost' => isset($row->unit_cost) ? (float) $row->unit_cost : null,
            ])->values();
        }

        if ($pairs->count() > self::MAX_PAIRS) {
            throw ValidationException::withMessages([
                'pairs' => 'You can export at most '.self::MAX_PAIRS.' stock positions at once.',
            ]);
        }

        return $pairs;
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    public function resolvePairsFromRequest(Request $request, ?int $scopedOfficeId = null): Collection
    {
        $categoryId = $request->query('category');
        $search = $request->query('search');
        $restockFilter = (string) $request->query('restock_filter', 'active');
        $pairKeys = $this->pairKeysFromRequest($request);

        return $this->resolvePairs(
            categoryId: $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null,
            search: is_string($search) && $search !== '' ? $search : null,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
            explicitPairKeys: $pairKeys,
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return Collection<int, array{item_id: int, office_id: int}>
     */
    public function pairsWithoutUnitCost(Collection $pairs): Collection
    {
        return $pairs->map(fn (array $pair): array => [
            'item_id' => $pair['item_id'],
            'office_id' => $pair['office_id'],
        ]);
    }
}
