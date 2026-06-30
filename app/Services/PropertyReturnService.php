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

        DB::transaction(function () use ($transfer): void {
            $unit = InventoryUnit::query()
                ->where('property_number', $transfer->property_number)
                ->where('status', InventoryUnit::STATUS_ISSUED)
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                return;
            }

            $unit->update([
                'status' => InventoryUnit::STATUS_IN_STOCK,
                'issuance_id' => null,
                'office_id' => $transfer->to_office_id,
            ]);
        });
    }
}
