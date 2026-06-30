<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->string('property_number')->unique();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_stock');
            $table->foreignId('issuance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('article')->nullable();
            $table->string('description')->nullable();
            $table->string('stock_number')->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'status']);
            $table->index(['item_id', 'office_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
    }
};
