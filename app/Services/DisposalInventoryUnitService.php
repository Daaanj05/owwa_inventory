<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use Illuminate\Database\Eloquent\Builder;

class DisposalInventoryUnitService
{
    /**
     * @return Builder<InventoryUnit>
     */
    public function availableUnitsQuery(?int $itemId, ?int $officeId, ?int $excludeDisposalId = null): Builder
    {
        if (blank($itemId) || blank($officeId)) {
            return InventoryUnit::query()->whereRaw('1 = 0');
        }

        $claimedUnitIds = Disposal::query()
            ->whereNotNull('inventory_unit_id')
            ->when($excludeDisposalId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $excludeDisposalId))
            ->pluck('inventory_unit_id');

        return InventoryUnit::query()
            ->with(['issuance', 'acquisition'])
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->whereIn('status', [InventoryUnit::STATUS_IN_STOCK, InventoryUnit::STATUS_ISSUED])
            ->when(
                $claimedUnitIds->isNotEmpty(),
                fn (Builder $query): Builder => $query->whereNotIn('id', $claimedUnitIds),
            );
    }

    public function hasAvailableUnits(?int $itemId, ?int $officeId, ?int $excludeDisposalId = null): bool
    {
        return $this->availableUnitsQuery($itemId, $officeId, $excludeDisposalId)->exists();
    }

    /**
     * @return array<int, string>
     */
    public function unitOptions(?int $itemId, ?int $officeId, ?int $excludeDisposalId = null): array
    {
        return $this->availableUnitsQuery($itemId, $officeId, $excludeDisposalId)
            ->orderBy('property_number')
            ->get()
            ->mapWithKeys(function (InventoryUnit $unit): array {
                $label = $unit->property_number;
                $status = str_replace('_', ' ', $unit->status);
                $ics = $unit->issuance?->reference_code;

                if ($ics !== null && $ics !== '') {
                    $label .= ' — '.$status.' — ICS '.$ics;
                } else {
                    $label .= ' — '.$status;
                }

                return [$unit->id => $label];
            })
            ->all();
    }

    public function applyUnitToFormState(InventoryUnit $unit, callable $set): void
    {
        $unit->loadMissing('issuance');

        $set('property_number', $unit->property_number);
        $set('quantity', 1);

        if ($unit->issuance_id !== null) {
            $set('par_issuance_id', $unit->issuance_id);
            $set('department_id', $unit->issuance?->department_id);
        } else {
            $set('par_issuance_id', null);
            $set('department_id', null);
        }

        $cost = $this->resolveAcquisitionCostForUnit($unit);
        if ($cost !== null) {
            $set('acquisition_cost', $cost);
        }

        $set('inventory_auto_synced', true);
    }

    public function resolveLatestAcquisitionCost(?int $itemId, ?int $officeId): ?float
    {
        if (blank($itemId)) {
            return null;
        }

        $cost = Acquisition::query()
            ->where('item_id', $itemId)
            ->when(filled($officeId), fn (Builder $query): Builder => $query->where('office_id', $officeId))
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($cost !== null) {
            return (float) $cost;
        }

        if (blank($officeId)) {
            return null;
        }

        $units = $this->availableUnitsQuery($itemId, $officeId)->get();

        if ($units->count() === 1) {
            return $this->resolveAcquisitionCostForUnit($units->first());
        }

        return null;
    }

    public function syncFormStateForItemOffice(?int $itemId, ?int $officeId, callable $set, ?int $excludeDisposalId = null): void
    {
        if (blank($itemId) || blank($officeId)) {
            $this->clearUnitLinkedFields($set);

            return;
        }

        $units = $this->availableUnitsQuery($itemId, $officeId, $excludeDisposalId)
            ->orderBy('property_number')
            ->get();

        if ($units->count() === 1) {
            $unit = $units->first();
            $set('inventory_unit_id', $unit->id);
            $this->applyUnitToFormState($unit, $set);

            return;
        }

        $set('inventory_unit_id', null);
        $set('property_number', null);
        $set('par_issuance_id', null);

        $cost = $this->resolveLatestAcquisitionCost($itemId, $officeId);
        $set('acquisition_cost', $cost);
        $set('inventory_auto_synced', $cost !== null);
    }

    public function clearUnitLinkedFields(callable $set): void
    {
        $set('inventory_unit_id', null);
        $set('property_number', null);
        $set('acquisition_cost', null);
        $set('par_issuance_id', null);
        $set('department_id', null);
        $set('inventory_auto_synced', false);
    }

    public function resolveAcquisitionCostForUnit(InventoryUnit $unit): ?float
    {
        $unit->loadMissing(['acquisition', 'issuance']);

        if ($unit->acquisition?->unit_cost !== null) {
            return (float) $unit->acquisition->unit_cost;
        }

        if ($unit->issuance?->unit_cost !== null) {
            return (float) $unit->issuance->unit_cost;
        }

        if ($unit->acquisition_id !== null) {
            $cost = Acquisition::query()->whereKey($unit->acquisition_id)->value('unit_cost');

            return $cost !== null ? (float) $cost : null;
        }

        if ($unit->issuance_id !== null) {
            $cost = Issuance::query()->whereKey($unit->issuance_id)->value('unit_cost');

            return $cost !== null ? (float) $cost : null;
        }

        return null;
    }

    public function markUnitDisposed(Disposal $disposal): void
    {
        if ($disposal->inventory_unit_id === null) {
            return;
        }

        InventoryUnit::query()
            ->whereKey($disposal->inventory_unit_id)
            ->where('status', '!=', InventoryUnit::STATUS_DISPOSED)
            ->update(['status' => InventoryUnit::STATUS_DISPOSED]);
    }

    public function resolvePropertyNumber(Disposal $disposal): ?string
    {
        $disposal->loadMissing(['inventoryUnit', 'item']);

        if (filled($disposal->inventoryUnit?->property_number)) {
            return (string) $disposal->inventoryUnit->property_number;
        }

        if (filled($disposal->property_number)) {
            return (string) $disposal->property_number;
        }

        return $disposal->item?->item_code;
    }

    public function resolveAcquisitionCost(Disposal $disposal): ?float
    {
        if ($disposal->acquisition_cost !== null) {
            return (float) $disposal->acquisition_cost;
        }

        $disposal->loadMissing('inventoryUnit');

        if ($disposal->inventoryUnit === null) {
            return null;
        }

        return $this->resolveAcquisitionCostForUnit($disposal->inventoryUnit);
    }
}
