<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Transfer;
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
            if (filled($transfer->property_number)) {
                $this->moveUnitByPropertyNumber($transfer);

                return;
            }

            $this->moveUnitsByQuantity($transfer);
        });
    }

    protected function moveUnitByPropertyNumber(Transfer $transfer): void
    {
        $propertyNumber = trim((string) $transfer->property_number);
        if ($propertyNumber === '') {
            return;
        }

        $unit = InventoryUnit::query()
            ->where('property_number', $propertyNumber)
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

        $units = InventoryUnit::query()
            ->where('item_id', $transfer->item_id)
            ->where('office_id', $transfer->from_office_id)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->orderBy('id')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        foreach ($units as $unit) {
            $unit->update([
                'office_id' => $transfer->to_office_id,
                'status' => InventoryUnit::STATUS_IN_STOCK,
            ]);
        }
    }
}
