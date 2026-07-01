<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->foreignId('requesting_office_id')
                ->nullable()
                ->after('department_id')
                ->constrained('offices')
                ->nullOnDelete();
        });

        DB::table('acquisition_paperwork')
            ->whereNotNull('department_id')
            ->update([
                'requesting_office_id' => DB::raw('office_id'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requesting_office_id');
        });
    }
};
