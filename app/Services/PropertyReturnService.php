<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class PropertyReturnService
{
    public function processReturnTransfer(Transfer $transfer): void
    {
        if ($transfer->transfer_type !== 'return') {
            return;
        }

        if (blank($transfer->property_number) && blank($transfer->inventory_unit_id)) {
            return;
        }

        $quantity = max(1, (int) $transfer->quantity);

        DB::transaction(function () use ($transfer, $quantity): void {
            if (filled($transfer->inventory_unit_id)) {
                $selected = InventoryUnit::query()
                    ->whereKey($transfer->inventory_unit_id)
                    ->where('item_id', $transfer->item_id)
                    ->where('status', InventoryUnit::STATUS_ISSUED)
                    ->lockForUpdate()
                    ->first();

                if ($selected !== null) {
                    $selected->update([
                        'status' => InventoryUnit::STATUS_IN_STOCK,
                        'issuance_id' => null,
                        'office_id' => $transfer->to_office_id,
                    ]);
                    $quantity--;
                }
            }

            if ($quantity < 1 || blank($transfer->property_number)) {
                return;
            }

            $units = InventoryUnit::query()
                ->where('property_number', $transfer->property_number)
                ->where('item_id', $transfer->item_id)
                ->where('status', InventoryUnit::STATUS_ISSUED)
                ->orderBy('id')
                ->limit($quantity)
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                $unit->update([
                    'status' => InventoryUnit::STATUS_IN_STOCK,
                    'issuance_id' => null,
                    'office_id' => $transfer->to_office_id,
                ]);
            }
        });
    }
}
