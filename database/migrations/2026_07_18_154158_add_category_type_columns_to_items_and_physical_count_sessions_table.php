<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (! Schema::hasColumn('items', 'inventory_type')) {
                $table->string('inventory_type', 50)->nullable()->after('property_class');
            }

            if (! Schema::hasColumn('items', 'ppe_type')) {
                $table->string('ppe_type', 50)->nullable()->after('inventory_type');
            }
        });

        Schema::table('physical_count_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('physical_count_sessions', 'inventory_type')) {
                $table->string('inventory_type', 50)->nullable()->after('inventory_type_label');
            }

            if (! Schema::hasColumn('physical_count_sessions', 'ppe_type')) {
                $table->string('ppe_type', 50)->nullable()->after('property_class');
            }
        });

        $ppeCategoryIds = ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'ppe')
            ->pluck('id')
            ->all();

        if ($ppeCategoryIds !== []) {
            Item::query()
                ->whereIn('item_category_id', $ppeCategoryIds)
                ->whereNotNull('property_class')
                ->whereNull('ppe_type')
                ->orderBy('id')
                ->each(function (Item $item): void {
                    $item->forceFill([
                        'ppe_type' => $item->property_class,
                        'property_class' => null,
                    ])->saveQuietly();
                });
        }

        PhysicalCountSession::query()
            ->where('count_type', PhysicalCountSession::TYPE_RPCPPE)
            ->whereNotNull('property_class')
            ->whereNull('ppe_type')
            ->orderBy('id')
            ->each(function (PhysicalCountSession $session): void {
                $session->forceFill([
                    'ppe_type' => $session->property_class,
                    'property_class' => null,
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        $ppeCategoryIds = ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'ppe')
            ->pluck('id')
            ->all();

        if ($ppeCategoryIds !== []) {
            Item::query()
                ->whereIn('item_category_id', $ppeCategoryIds)
                ->whereNotNull('ppe_type')
                ->whereNull('property_class')
                ->orderBy('id')
                ->each(function (Item $item): void {
                    $item->forceFill([
                        'property_class' => $item->ppe_type,
                        'ppe_type' => null,
                    ])->saveQuietly();
                });
        }

        PhysicalCountSession::query()
            ->where('count_type', PhysicalCountSession::TYPE_RPCPPE)
            ->whereNotNull('ppe_type')
            ->whereNull('property_class')
            ->orderBy('id')
            ->each(function (PhysicalCountSession $session): void {
                $session->forceFill([
                    'property_class' => $session->ppe_type,
                    'ppe_type' => null,
                ])->saveQuietly();
            });

        Schema::table('physical_count_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('physical_count_sessions', 'ppe_type')) {
                $table->dropColumn('ppe_type');
            }

            if (Schema::hasColumn('physical_count_sessions', 'inventory_type')) {
                $table->dropColumn('inventory_type');
            }
        });

        Schema::table('items', function (Blueprint $table): void {
            if (Schema::hasColumn('items', 'ppe_type')) {
                $table->dropColumn('ppe_type');
            }

            if (Schema::hasColumn('items', 'inventory_type')) {
                $table->dropColumn('inventory_type');
            }
        });
    }
};
