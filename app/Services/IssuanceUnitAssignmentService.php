<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
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
        $issuingOfficeId = (int) ($issuance->issued_from_office_id ?? $issuance->office_id ?? 0);

        $unitIds = $this->inStockUnitIds($issuance, $needed, $issuingOfficeId);

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
            $issuance->issued_property_numbers = $reserved
                ->pluck('property_number')
                ->filter()
                ->values()
                ->all();
            $issuance->reservedInventoryUnitIds = $reserved
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        });
    }

    /**
     * In-stock units at the issuing office whose unit cost matches the lot the issuance deducts.
     *
     * @return Collection<int, int>
     */
    protected function inStockUnitIds(Issuance $issuance, int $needed, int $issuingOfficeId): Collection
    {
        $costKey = UnitCostKey::normalize($issuance->unit_cost !== null ? (float) $issuance->unit_cost : null);

        return InventoryUnit::query()
            ->where('item_id', $issuance->item_id)
            ->where('office_id', $issuingOfficeId)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->when(
                $costKey === UnitCostKey::NULL_SENTINEL,
                fn ($query) => $query->where(fn ($inner) => $inner->whereNull('unit_cost')->orWhere('unit_cost', 0)),
                fn ($query) => $query->where('unit_cost', $costKey),
            )
            ->orderBy('id')
            ->limit($needed)
            ->pluck('id');
    }
}
