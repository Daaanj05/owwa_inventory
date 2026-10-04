<?php

namespace App\Services;

use App\Models\Item;
use App\Support\UnitCostKey;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockLevelExportService
{
    public const int BATCH_SIZE = 100;

    public const int SINGLE_MAX = 500;

    /** Hard cap for Fast sync ZIP pack requests (multiple BATCH_SIZE files). */
    public const int FAST_MAX = 5000;

    /** @deprecated Use BATCH_SIZE */
    public const int MAX_PAIRS = self::BATCH_SIZE;

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

    public function exportMaxFromRequest(Request $request): int
    {
        $exportMax = (int) $request->query('export_max', self::BATCH_SIZE);
        $cap = $request->boolean('fast_pack')
            ? self::FAST_MAX
            : self::SINGLE_MAX;

        if ($exportMax <= self::BATCH_SIZE) {
            return min(self::BATCH_SIZE, $cap);
        }

        return min($exportMax, $cap);
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
        $rows = $this->stockService->listExportStockPositions(
            ($categoryId !== null && $categoryId > 0) ? $categoryId : null,
            ($scopedOfficeId !== null && $scopedOfficeId > 0) ? $scopedOfficeId : null,
        );

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
    public function collectPairs(
        ?int $categoryId,
        ?string $search,
        string $restockFilter,
        ?int $scopedOfficeId,
        array $explicitPairKeys = [],
    ): Collection {
        if ($explicitPairKeys !== []) {
            return $this->resolveExplicitPairs($explicitPairKeys, $categoryId, $scopedOfficeId);
        }

        return $this->filterStockLevelRows($categoryId, $search, $restockFilter, $scopedOfficeId)
            ->map(fn (object $row): array => [
                'item_id' => (int) $row->item_id,
                'office_id' => (int) $row->office_id,
                'unit_cost' => isset($row->unit_cost) ? (float) $row->unit_cost : null,
            ])->values();
    }

    public function countPairs(
        ?int $categoryId,
        ?string $search,
        string $restockFilter,
        ?int $scopedOfficeId,
        array $explicitPairKeys = [],
    ): int {
        if ($explicitPairKeys !== []) {
            return $this->resolveExplicitPairs($explicitPairKeys, $categoryId, $scopedOfficeId)->count();
        }

        return $this->filterStockLevelRows($categoryId, $search, $restockFilter, $scopedOfficeId)->count();
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
        ?int $maxPairs = null,
    ): Collection {
        $maxPairs = $maxPairs ?? self::BATCH_SIZE;
        $maxPairs = max(1, min($maxPairs, self::SINGLE_MAX));

        $pairs = $this->collectPairs(
            $categoryId,
            $search,
            $restockFilter,
            $scopedOfficeId,
            $explicitPairKeys,
        );

        if ($pairs->isEmpty()) {
            throw ValidationException::withMessages([
                'pairs' => $explicitPairKeys !== []
                    ? 'None of the selected stock positions could be exported. Clear filters or reselect rows.'
                    : 'No stock positions matched the current filters.',
            ]);
        }

        if ($pairs->count() > $maxPairs) {
            throw ValidationException::withMessages([
                'pairs' => 'You can export at most '.$maxPairs.' stock positions at once.',
            ]);
        }

        return $pairs;
    }

    /**
     * @param  array<int, string>  $explicitPairKeys
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    protected function resolveExplicitPairs(
        array $explicitPairKeys,
        ?int $categoryId,
        ?int $scopedOfficeId,
    ): Collection {
        $decodedPairs = [];

        foreach ($explicitPairKeys as $pairKey) {
            $decoded = $this->decodePairKey($pairKey);
            if ($decoded === null) {
                continue;
            }

            if ($scopedOfficeId !== null && $scopedOfficeId > 0 && $decoded['office_id'] !== $scopedOfficeId) {
                continue;
            }

            $decodedPairs[] = $decoded;
        }

        if ($decodedPairs === []) {
            return collect();
        }

        $itemIds = array_values(array_unique(array_map(
            fn (array $pair): int => $pair['item_id'],
            $decodedPairs,
        )));

        $itemsById = Item::query()
            ->whereIn('id', $itemIds)
            ->get(['id', 'item_category_id'])
            ->keyBy('id');

        $categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
        $resolved = collect();

        foreach ($decodedPairs as $decoded) {
            $item = $itemsById->get($decoded['item_id']);
            if ($item === null) {
                continue;
            }

            if ($categoryId !== null && (int) $item->item_category_id !== $categoryId) {
                continue;
            }

            $resolved->push([
                'item_id' => $decoded['item_id'],
                'office_id' => $decoded['office_id'],
                'unit_cost' => $decoded['unit_cost'],
            ]);
        }

        return $resolved->unique(fn (array $pair): string => $this->encodePairKey(
            $pair['item_id'],
            $pair['office_id'],
            $pair['unit_cost'],
        ))->values();
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
            maxPairs: $this->exportMaxFromRequest($request),
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>|array<int, string>  $pairsOrKeys
     * @return array<int, array<int, string>>
     */
    public function chunkPairKeys(Collection|array $pairsOrKeys, ?int $chunkSize = null): array
    {
        $chunkSize = $chunkSize ?? self::BATCH_SIZE;

        if ($pairsOrKeys instanceof Collection) {
            $keys = $pairsOrKeys->map(fn (array $pair): string => $this->encodePairKey(
                $pair['item_id'],
                $pair['office_id'],
                $pair['unit_cost'],
            ))->values()->all();
        } else {
            $keys = array_values($pairsOrKeys);
        }

        if ($keys === []) {
            return [];
        }

        return array_values(array_chunk($keys, max(1, $chunkSize)));
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    public function takeLimited(Collection $pairs): Collection
    {
        return $pairs->take(self::BATCH_SIZE)->values();
    }
}
