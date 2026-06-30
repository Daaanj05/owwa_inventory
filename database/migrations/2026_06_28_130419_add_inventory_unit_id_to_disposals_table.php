<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disposals', function (Blueprint $table) {
            $table->foreignId('inventory_unit_id')
                ->nullable()
                ->after('item_id')
                ->constrained('inventory_units')
                ->nullOnDelete();

            $table->unique('inventory_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('disposals', function (Blueprint $table) {
            $table->dropUnique(['inventory_unit_id']);
            $table->dropConstrainedForeignId('inventory_unit_id');
        });
    }
};
