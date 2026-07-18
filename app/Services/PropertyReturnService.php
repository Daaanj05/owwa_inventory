<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class PropertyReturnService
{
    public function processReturnTransfer(Transfer $transfer): void
    {
        if ($transfer->transfer_type !== 'return' || blank($transfer->property_number)) {
            return;
        }

        $quantity = max(1, (int) $transfer->quantity);

        DB::transaction(function () use ($transfer, $quantity): void {
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
