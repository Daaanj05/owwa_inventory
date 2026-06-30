<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CANONICAL_NAME = 'Property, Plant and Equipment';

    /** @var list<string> */
    private const LEGACY_PPE_NAMES = [
        'PPE',
        'Power Plant Equipment',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('item_categories')) {
            return;
        }

        DB::transaction(function (): void {
            $categories = ItemCategory::query()
                ->whereIn('name', [...self::LEGACY_PPE_NAMES, self::CANONICAL_NAME])
                ->get();

            if ($categories->isEmpty()) {
                return;
            }

            $canonical = $categories->firstWhere('name', self::CANONICAL_NAME);

            $legacy = $categories->filter(
                fn (ItemCategory $category): bool => in_array($category->name, self::LEGACY_PPE_NAMES, true)
            );

            $keeper = $this->resolveKeeper($canonical, $legacy);

            if ($keeper === null) {
                return;
            }

            $duplicateIds = $categories
                ->pluck('id')
                ->filter(fn (int $id): bool => $id !== $keeper->id)
                ->values();

            if ($duplicateIds->isNotEmpty() && Schema::hasTable('items')) {
                DB::table('items')
                    ->whereIn('item_category_id', $duplicateIds)
                    ->update(['item_category_id' => $keeper->id]);
            }

            $keeper->update([
                'name' => self::CANONICAL_NAME,
                'description' => $keeper->description ?: 'Property, plant and equipment (PPE)',
            ]);

            foreach ($duplicateIds as $duplicateId) {
                $hasItems = Schema::hasTable('items')
                  && DB::table('items')->where('item_category_id', $duplicateId)->exists();

                if ($hasItems) {
                    continue;
                }

                ItemCategory::query()
                    ->whereKey($duplicateId)
                    ->update(['archived_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible data merge.
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ItemCategory>  $legacy
     */
    private function resolveKeeper(?ItemCategory $canonical, $legacy): ?ItemCategory
    {
        if ($canonical !== null) {
            return $canonical;
        }

        if ($legacy->isEmpty()) {
            return null;
        }

        $withItems = $legacy->sortByDesc(
            fn (ItemCategory $category): int => Schema::hasTable('items')
              ? DB::table('items')->where('item_category_id', $category->id)->count()
              : 0
        );

        $ppe = $withItems->firstWhere('name', 'PPE');

        return $ppe ?? $withItems->first();
    }
};
