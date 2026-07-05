<?php

namespace App\Support;

use App\Models\ReferenceSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryDemoReset
{
    /**
     * Tables truncated in FK-safe order (children before parents).
     *
     * @return list<string>
     */
    public static function inventoryTables(): array
    {
        return [
            'physical_count_scan_events',
            'physical_count_lines',
            'physical_count_sessions',
            'physical_inventory_plan_lines',
            'physical_inventory_plans',
            'stock_position_restock_flags',
            'useful_life_extensions',
            'inventory_units',
            'item_stock_buckets',
            'property_number_buckets',
            'distributions',
            'requisition_items',
            'requisitions',
            'disposals',
            'transfers',
            'issuances',
            'acquisitions',
            'acquisition_paperwork_lines',
            'acquisition_paperwork',
            'ai_procurement_items',
            'ai_procurement_runs',
            'items',
        ];
    }

    public static function truncateInventoryTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::inventoryTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }

    public static function resetTransactionReferenceSeries(): void
    {
        ReferenceSeries::query()
            ->whereIn('type', ReferenceSeries::transactionSeriesTypes())
            ->update([
                'next_sequence' => 1,
                'last_generated_at' => null,
            ]);
    }
}
