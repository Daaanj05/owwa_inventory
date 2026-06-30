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
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('description');
        });

        Schema::table('reference_series', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('last_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });

        Schema::table('reference_series', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
