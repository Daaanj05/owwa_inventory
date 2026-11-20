<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table): void {
            $table->integer('eul_days_remaining')->nullable()->after('estimated_useful_life');
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table): void {
            $table->dropColumn('eul_days_remaining');
        });
    }
};
