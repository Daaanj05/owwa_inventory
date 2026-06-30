<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->string('action_status', 24)->nullable()->after('include_in_request');
            $table->timestamp('action_updated_at')->nullable()->after('action_status');
            $table->text('action_notes')->nullable()->after('action_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_items', function (Blueprint $table) {
            $table->dropColumn(['action_status', 'action_updated_at', 'action_notes']);
        });
    }
};
