<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CANONICAL_NAME = 'Property, Plant and Equipment';

    /** @var list<string> */
    private const LEGACY_NAMES = [
        'PPE',
        'Power Plant Equipment',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('item_categories')) {
            return;
        }

        DB::transaction(function (): void {
            $canonical = ItemCategory::query()->firstOrCreate(
                ['name' => self::CANONICAL_NAME],
                ['description' => 'Property, plant and equipment (PPE)'],
            );

            $legacyIds = ItemCategory::query()
                ->whereIn('name', self::LEGACY_NAMES)
                ->pluck('id');

            if ($legacyIds->isEmpty()) {
                return;
            }

            if (Schema::hasTable('items')) {
                DB::table('items')
                    ->whereIn('item_category_id', $legacyIds)
                    ->update(['item_category_id' => $canonical->id]);
            }

            if (Schema::hasTable('procurement_cases')) {
                DB::table('procurement_cases')
                    ->whereIn('item_category_id', $legacyIds)
                    ->update(['item_category_id' => $canonical->id]);
            }

            ItemCategory::query()
                ->whereIn('id', $legacyIds)
                ->update(['archived_at' => now()]);
        });
    }

    public function down(): void
    {
        // Irreversible archive.
    }
};
