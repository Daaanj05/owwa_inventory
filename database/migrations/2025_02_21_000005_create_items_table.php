<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit')->default('piece'); // piece, box, ream, etc.
            $table->string('item_code')->unique()->nullable();
            $table->enum('value_type', ['low', 'high'])->default('low');
            $table->unsignedInteger('reorder_level')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
