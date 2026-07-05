<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_units', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('office_id');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_units', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
