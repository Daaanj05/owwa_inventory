<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_action_request_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('inventory_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('property_action_request_lines', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
