<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_case_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('line_remarks')->nullable();
            $table->foreignId('acquisition_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_case_lines');
    }
};
