<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_count_sessions', function (Blueprint $table) {
            $table->string('status', 20)->default('in_progress')->after('count_type');
            $table->timestamp('completed_at')->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('physical_count_sessions', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at']);
        });
    }
};
