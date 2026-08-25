<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_count_scan_events', function (Blueprint $table) {
            $table->foreignId('inventory_unit_id')
                ->nullable()
                ->after('physical_count_line_id')
                ->constrained('inventory_units')
                ->nullOnDelete();

            $table->index(
                ['physical_count_session_id', 'inventory_unit_id'],
                'pc_scan_events_session_unit_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('physical_count_scan_events', function (Blueprint $table) {
            $table->dropIndex('pc_scan_events_session_unit_index');
            $table->dropConstrainedForeignId('inventory_unit_id');
        });
    }
};
