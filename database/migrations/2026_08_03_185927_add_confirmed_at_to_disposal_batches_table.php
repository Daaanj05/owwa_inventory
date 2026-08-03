<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disposal_batches', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('witness_printed_name');
        });

        DB::table('disposal_batches')
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('disposal_batches', function (Blueprint $table): void {
            $table->dropColumn('confirmed_at');
        });
    }
};
