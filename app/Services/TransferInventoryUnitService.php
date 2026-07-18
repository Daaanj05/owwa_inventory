<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Transfer;
use App\Support\UnitCostKey;
use Illuminate\Support\Facades\DB;

class TransferInventoryUnitService
{
    public function syncUnitsForTransfer(Transfer $transfer): void
    {
        $transfer->loadMissing(['item.category']);

        $slug = $transfer->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return;
        }

        DB::transaction(function () use ($transfer): void {
            // Catalog property / inventory item numbers are shared across units of the same item.
            // Prefer quantity-based moves; only honor a stored unit id when present.
            if (filled($transfer->inventory_unit_id) && (int) $transfer->quantity === 1) {
                $this->moveUnitById($transfer);

                return;
            }

            $this->moveUnitsByQuantity($transfer);
        });
    }

    protected function moveUnitById(Transfer $transfer): void
    {
        $unit = InventoryUnit::query()
            ->whereKey($transfer->inventory_unit_id)
            ->where('item_id', $transfer->item_id)
            ->where('office_id', $transfer->from_office_id)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            return;
        }

        $unit->update([
            'office_id' => $transfer->to_office_id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
        ]);
    }

    protected function moveUnitsByQuantity(Transfer $transfer): void
    {
        $quantity = max(0, (int) $transfer->quantity);
        if ($quantity === 0) {
            return;
        }

        $query = InventoryUnit::query()
            ->where('item_id', $transfer->item_id)
            ->where('office_id', $transfer->from_office_id)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->orderBy('id');

        if ($transfer->unit_cost !== null) {
            $normalized = UnitCostKey::normalize((float) $transfer->unit_cost);
            $query->where('unit_cost', $normalized);
        }

        $units = $query->limit($quantity)->lockForUpdate()->get();

        foreach ($units as $unit) {
            $unit->update([
                'office_id' => $transfer->to_office_id,
                'status' => InventoryUnit::STATUS_IN_STOCK,
            ]);
        }
    }
}
