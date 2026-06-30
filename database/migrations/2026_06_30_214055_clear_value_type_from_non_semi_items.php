<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('item_categories')) {
            return;
        }

        $this->makeValueTypeNullable();

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

    private function makeValueTypeNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE items ALTER COLUMN value_type DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql') {
            Schema::table('items', function (Blueprint $table): void {
                $table->enum('value_type', ['low', 'high'])->nullable()->change();
            });

            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->string('value_type', 10)->nullable()->change();
        });
    }
};
