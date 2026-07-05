<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_position_restock_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('is_inactive_for_restock')->default(false);
            $table->timestamp('inactive_at')->nullable();
            $table->foreignId('inactive_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('inactive_note')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'office_id', 'unit_cost'], 'stock_position_restock_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_position_restock_flags');
    }
};
