<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $itemIds = DB::table('item_stock_buckets')
            ->whereNotNull('property_number')
            ->distinct()
            ->pluck('item_id');

        foreach ($itemIds as $itemId) {
            $canonical = DB::table('item_stock_buckets')
                ->where('item_id', $itemId)
                ->whereNotNull('property_number')
                ->orderBy('id')
                ->value('property_number');

            if ($canonical === null) {
                continue;
            }

            DB::table('items')
                ->where('id', $itemId)
                ->whereNull('semi_expendable_property_number')
                ->update(['semi_expendable_property_number' => $canonical]);

            DB::table('item_stock_buckets')
                ->where('item_id', $itemId)
                ->update(['property_number' => $canonical]);

            DB::table('inventory_units')
                ->where('item_id', $itemId)
                ->where(function ($query) use ($canonical): void {
                    $query->whereNull('property_number')
                        ->orWhere('property_number', '!=', $canonical);
                })
                ->update(['property_number' => $canonical]);
        }
    }

    public function down(): void
    {
        // Non-reversible: prior per-cost property numbers are not restored.
    }
};
