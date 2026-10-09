<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->string('property_number', 255)->nullable()->after('item_name');
            $table->string('eul_status', 64)->nullable()->after('property_number');
            $table->string('replacement_action', 32)->nullable()->after('eul_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->dropColumn([
                'property_number',
                'eul_status',
                'replacement_action',
            ]);
        });
    }
};
