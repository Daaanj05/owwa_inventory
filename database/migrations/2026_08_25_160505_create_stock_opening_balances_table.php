<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_cost', 12, 2);
            $table->unsignedInteger('quantity');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'office_id', 'unit_cost'], 'stock_opening_balances_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opening_balances');
    }
};
