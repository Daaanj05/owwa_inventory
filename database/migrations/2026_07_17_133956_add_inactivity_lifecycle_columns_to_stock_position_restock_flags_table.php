<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_position_restock_flags', function (Blueprint $table) {
            $table->date('zero_stock_since')->nullable()->after('inactive_note');
            $table->string('inactive_source', 20)->nullable()->after('zero_stock_since');
            $table->date('auto_inactive_snoozed_until')->nullable()->after('inactive_source');
            $table->index(['is_inactive_for_restock', 'inactive_source'], 'stock_restock_inactive_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_position_restock_flags', function (Blueprint $table) {
            $table->dropIndex('stock_restock_inactive_source_idx');
            $table->dropColumn([
                'zero_stock_since',
                'inactive_source',
                'auto_inactive_snoozed_until',
            ]);
        });
    }
};
