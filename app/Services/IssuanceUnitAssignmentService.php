<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Support\SupplyOfficeResolver;
use App\Support\UnitCostKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssuanceUnitAssignmentService
{
    public function assignUnitToIssuance(Issuance $issuance): void
    {
        $item = $issuance->relationLoaded('item')
            ? $issuance->item
            : ($issuance->item_id ? Item::with('category')->find($issuance->item_id) : null);

        $slug = $item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return;
        }

        if (filled($issuance->property_number)) {
            return;
        }

        $needed = max(1, (int) $issuance->quantity);
        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();
        $requestingOfficeId = (int) ($issuance->office_id ?? 0);

        $unitIds = $this->inStockUnitIds($issuance, $needed, $supplyOfficeId, $requestingOfficeId, matchUnitCost: true);

        if ($unitIds->count() < $needed && $issuance->unit_cost !== null) {
            $unitIds = $this->inStockUnitIds($issuance, $needed, $supplyOfficeId, $requestingOfficeId, matchUnitCost: false);
        }

        if ($unitIds->count() < $needed) {
            throw ValidationException::withMessages([
                'property_number' => $unitIds->isEmpty()
                    ? 'No in-stock inventory unit with an assigned property or inventory number. Record acquisition first.'
                    : "Only {$unitIds->count()} in-stock unit(s) are available to issue.",
            ]);
        }

        DB::transaction(function () use ($issuance, $unitIds, $needed): void {
            $locked = InventoryUnit::query()
                ->whereIn('id', $unitIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $available = $locked->filter(
                fn (InventoryUnit $unit): bool => $unit->status === InventoryUnit::STATUS_IN_STOCK,
            );

            if ($available->count() < $needed) {
                throw ValidationException::withMessages([
                    'property_number' => 'No in-stock inventory unit with an assigned property or inventory number. Record acquisition first.',
                ]);
            }

            $reserved = $available->take($needed)->values();

            InventoryUnit::query()
                ->whereIn('id', $reserved->pluck('id'))
                ->update([
                    'status' => InventoryUnit::STATUS_ISSUED,
                ]);

            $issuance->property_number = $reserved->first()?->property_number;
            $issuance->reservedInventoryUnitIds = $reserved
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        });
    }

    /**
     * @return Collection<int, int>
     */
    protected function inStockUnitIds(
        Issuance $issuance,
        int $needed,
        ?int $supplyOfficeId,
        int $requestingOfficeId,
        bool $matchUnitCost,
    ): Collection {
        $query = InventoryUnit::query()
            ->where('item_id', $issuance->item_id)
            ->where('status', InventoryUnit::STATUS_IN_STOCK);

        if ($matchUnitCost && $issuance->unit_cost !== null) {
            $query->where('unit_cost', UnitCostKey::normalize((float) $issuance->unit_cost));
        }

        return $query
            ->orderByRaw(
                'CASE office_id WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [$supplyOfficeId ?? 0, $requestingOfficeId],
            )
            ->orderBy('id')
            ->limit($needed)
            ->pluck('id');
    }
}
