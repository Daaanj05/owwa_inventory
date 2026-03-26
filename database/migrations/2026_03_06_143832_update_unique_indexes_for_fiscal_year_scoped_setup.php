<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropUnique('offices_code_unique');
            $table->unique(['fiscal_year_id', 'code']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique('items_item_code_unique');
            $table->unique(['fiscal_year_id', 'item_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_year_id', 'code']);
            $table->unique('code');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_year_id', 'item_code']);
            $table->unique('item_code');
        });
    }
};
