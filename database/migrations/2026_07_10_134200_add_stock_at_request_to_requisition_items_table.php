<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table): void {
            $table->unsignedInteger('stock_at_request')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table): void {
            $table->dropColumn('stock_at_request');
        });
    }
};
