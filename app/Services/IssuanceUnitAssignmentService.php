<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

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

        $unit = InventoryUnit::query()
            ->where('item_id', $issuance->item_id)
            ->where('office_id', $issuance->office_id)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->orderBy('id')
            ->first();

        if ($unit === null) {
            return;
        }

        DB::transaction(function () use ($issuance, $unit): void {
            $locked = InventoryUnit::query()
                ->whereKey($unit->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== InventoryUnit::STATUS_IN_STOCK) {
                return;
            }

            $locked->update([
                'status' => InventoryUnit::STATUS_ISSUED,
                'issuance_id' => $issuance->id,
            ]);

            $issuance->property_number = $locked->property_number;
        });
    }
}
