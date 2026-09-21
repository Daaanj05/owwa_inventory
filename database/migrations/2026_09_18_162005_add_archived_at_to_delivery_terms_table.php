<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_terms', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->index('archived_at');
        });

        DB::table('delivery_terms')
            ->where('is_active', false)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('delivery_terms', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
