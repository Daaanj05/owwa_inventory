<?php

namespace App\Support;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IssuableInventoryUnitOptions
{
    /**
     * FIFO in-stock units at the issuing office for PPE / semi-expendable issue selection.
     *
     * @return array<int, string> unit id => label
     */
    public function optionsForItem(int $itemId, int $officeId): array
    {
        return $this->unitsForItem($itemId, $officeId)
            ->mapWithKeys(fn (InventoryUnit $unit): array => [
                $unit->id => $this->labelFor($unit),
            ])
            ->all();
    }

    /**
     * First N unit ids in FIFO order (default selection for quantity to issue).
     *
     * @return list<int>
     */
    public function defaultUnitIds(int $itemId, int $officeId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }

        return $this->unitsForItem($itemId, $officeId)
            ->take($quantity)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, InventoryUnit>
     */
    public function unitsForItem(int $itemId, int $officeId): Collection
    {
        if ($itemId <= 0 || $officeId <= 0) {
            return collect();
        }

        return InventoryUnit::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->whereNotNull('property_number')
            ->where('property_number', '!=', '')
            ->orderBy('id')
            ->get(['id', 'property_number', 'unit_cost', 'item_id']);
    }

    public function labelFor(InventoryUnit $unit): string
    {
        $number = filled($unit->property_number) ? (string) $unit->property_number : 'Unit #'.$unit->id;

        return $this->isReturned($unit)
            ? $number.' — Returned'
            : $number;
    }

    public function isReturned(InventoryUnit $unit): bool
    {
        if ($this->hasApprovedReturnTransfer((int) $unit->id)) {
            return true;
        }

        $propertyNumber = trim((string) ($unit->property_number ?? ''));

        if ($propertyNumber === '') {
            return false;
        }

        return Issuance::query()
            ->where('item_id', $unit->item_id)
            ->where('property_number', $propertyNumber)
            ->whereNotNull('custody_ended_at')
            ->whereIn('custody_end_type', ['return', 'transfer', 'repair'])
            ->exists();
    }

    /**
     * Lock and validate selected units are still issuable; return them in selection order.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, InventoryUnit>
     */
    public function lockIssuableUnits(array $unitIds, int $itemId, int $officeId, int $expectedCount): Collection
    {
        $ids = collect($unitIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->count() !== $expectedCount) {
            throw new \InvalidArgumentException(
                "Select exactly {$expectedCount} inventory unit".($expectedCount === 1 ? '' : 's').' to issue.'
            );
        }

        $locked = InventoryUnit::query()
            ->whereIn('id', $ids->all())
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($locked->count() !== $ids->count()) {
            throw new \InvalidArgumentException(
                'One or more selected inventory units are no longer in regional stock.'
            );
        }

        return $ids
            ->map(fn (int $id): InventoryUnit => $locked->get($id))
            ->filter()
            ->values();
    }

    protected function hasApprovedReturnTransfer(int $inventoryUnitId): bool
    {
        return Transfer::query()
            ->where('inventory_unit_id', $inventoryUnitId)
            ->whereNotNull('approved_at')
            ->whereIn('transfer_type', [Transfer::TYPE_RETURN, Transfer::TYPE_RELOCATE])
            ->where(function (Builder $query): void {
                $query->whereExists(function ($exists): void {
                    $exists->selectRaw('1')
                        ->from('property_action_request_lines')
                        ->whereColumn('property_action_request_lines.transfer_id', 'transfers.id');
                })->orWhere('transfer_type', Transfer::TYPE_RETURN);
            })
            ->exists();
    }
}
