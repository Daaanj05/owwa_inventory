<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acquisition_paperwork_line_id')
                ->constrained('acquisition_paperwork_lines')
                ->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('pr_quantity');
            $table->unsignedInteger('po_quantity')->default(0);
            $table->boolean('is_ordered')->default(false);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('line_remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'acquisition_paperwork_line_id'], 'po_line_pr_line_unique');
            $table->index('acquisition_paperwork_line_id');
            $table->index(['purchase_order_id', 'is_ordered']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
