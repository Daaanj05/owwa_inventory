<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stock_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->string('property_number', 100)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'unit_cost']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stock_buckets');
    }
};
