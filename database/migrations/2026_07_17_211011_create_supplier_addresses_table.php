<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('address');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'address']);
            $table->index(['supplier_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
    }
};
