<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('item_categories')) {
            return;
        }

        $semiCategoryIds = ItemCategory::query()
            ->get(['id', 'name'])
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'semi_expendable')
            ->pluck('id');

        DB::table('items')
            ->when(
                $semiCategoryIds->isNotEmpty(),
                fn ($query) => $query->whereNotIn('item_category_id', $semiCategoryIds->all()),
                fn ($query) => $query
            )
            ->update(['value_type' => null]);
    }

    public function down(): void
    {
        // Irreversible cleanup.
    }
};
