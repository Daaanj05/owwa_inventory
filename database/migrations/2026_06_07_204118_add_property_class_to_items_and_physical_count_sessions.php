<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('property_class', 50)->nullable()->after('value_type');
        });

        Schema::table('physical_count_sessions', function (Blueprint $table) {
            $table->string('property_class', 50)->nullable()->after('inventory_type_label');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('property_class');
        });

        Schema::table('physical_count_sessions', function (Blueprint $table) {
            $table->dropColumn('property_class');
        });
    }
};
