<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opening_balances', function (Blueprint $table) {
            $table->foreignId('batch_id')
                ->nullable()
                ->after('id')
                ->constrained('stock_opening_balance_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opening_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });
    }
};
