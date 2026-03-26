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
        $defaultFiscalYearId = DB::table('fiscal_years')->orderBy('start_date')->value('id');

        Schema::table('offices', function (Blueprint $table) use ($defaultFiscalYearId): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->timestamp('archived_at')->nullable()->after('address');
        });
        if ($defaultFiscalYearId) {
            DB::table('offices')->whereNull('fiscal_year_id')->update(['fiscal_year_id' => $defaultFiscalYearId]);
        }

        Schema::table('departments', function (Blueprint $table) use ($defaultFiscalYearId): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->timestamp('archived_at')->nullable()->after('code');
        });
        if ($defaultFiscalYearId) {
            DB::table('departments')->whereNull('fiscal_year_id')->update(['fiscal_year_id' => $defaultFiscalYearId]);
        }

        Schema::table('items', function (Blueprint $table) use ($defaultFiscalYearId): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->timestamp('archived_at')->nullable()->after('description');
        });
        if ($defaultFiscalYearId) {
            DB::table('items')->whereNull('fiscal_year_id')->update(['fiscal_year_id' => $defaultFiscalYearId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
            $table->dropColumn('archived_at');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
            $table->dropColumn('archived_at');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
            $table->dropColumn('archived_at');
        });
    }
};
