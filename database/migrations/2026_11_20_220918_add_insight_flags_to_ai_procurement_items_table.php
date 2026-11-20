<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->json('insight_flags')->nullable()->after('reason');
            $table->decimal('spike_share', 8, 4)->nullable()->after('insight_flags');
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->dropColumn(['insight_flags', 'spike_share']);
        });
    }
};
