<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->timestamp('exported_at')->nullable()->after('recorded_by');
            $table->timestamp('approved_at')->nullable()->after('exported_at');
        });

        DB::table('transfers')->whereNull('approved_at')->update([
            'approved_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);

        Schema::table('disposals', function (Blueprint $table): void {
            $table->timestamp('exported_at')->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['exported_at', 'approved_at']);
        });

        Schema::table('disposals', function (Blueprint $table): void {
            $table->dropColumn('exported_at');
        });
    }
};
