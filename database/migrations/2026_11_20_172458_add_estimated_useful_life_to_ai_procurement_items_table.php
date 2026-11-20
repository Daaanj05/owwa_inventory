<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->string('estimated_useful_life')->nullable()->after('eul_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->dropColumn('estimated_useful_life');
        });
    }
};
