<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_procurement_runs', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->index('archived_at');
        });

        DB::table('ai_procurement_runs')
            ->where('status', 'archived')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $row): void {
                DB::table('ai_procurement_runs')
                    ->where('id', $row->id)
                    ->update([
                        'archived_at' => $row->updated_at ?? now(),
                        'status' => 'pending',
                    ]);
            });

        DB::table('ai_procurement_runs')
            ->whereIn('status', ['draft', 'for_approval'])
            ->update(['status' => 'pending']);

        Schema::table('ai_procurement_runs', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_procurement_runs', function (Blueprint $table) {
            $table->string('status', 32)->default('draft')->nullable(false)->change();
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
