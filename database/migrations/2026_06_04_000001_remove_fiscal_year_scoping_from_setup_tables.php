<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offices', 'fiscal_year_id')) {
            return;
        }

        Schema::table('offices', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_year_id']);
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_year_id', 'code']);
            $table->unique('code');
            $table->dropColumn('fiscal_year_id');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_fy_office_name_unique');
            $table->unique(['office_id', 'name'], 'departments_office_name_unique');
            $table->dropColumn('fiscal_year_id');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_year_id', 'item_code']);
            $table->unique('item_code');
            $table->dropColumn('fiscal_year_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('offices', 'fiscal_year_id')) {
            return;
        }

        Schema::table('offices', function (Blueprint $table): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained('fiscal_years')->cascadeOnDelete();
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->dropUnique('code');
            $table->unique(['fiscal_year_id', 'code']);
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_office_name_unique');
            $table->unique(['fiscal_year_id', 'office_id', 'name'], 'departments_fy_office_name_unique');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique('item_code');
            $table->unique(['fiscal_year_id', 'item_code']);
        });
    }
};
