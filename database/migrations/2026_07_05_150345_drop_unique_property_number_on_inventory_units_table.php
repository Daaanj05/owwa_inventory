<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_units', function (Blueprint $table) {
            $table->dropUnique(['property_number']);
            $table->index('property_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_units', function (Blueprint $table) {
            $table->dropIndex(['property_number']);
            $table->unique('property_number');
        });
    }
};
