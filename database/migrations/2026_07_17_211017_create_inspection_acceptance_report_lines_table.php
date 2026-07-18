<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inspection_acceptance_report_lines');

        Schema::create('inspection_acceptance_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_acceptance_report_id')
                ->constrained('inspection_acceptance_reports', 'id', 'iar_lines_iar_fk')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->unsignedBigInteger('acquisition_paperwork_line_id');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('pr_quantity');
            $table->unsignedInteger('po_quantity');
            $table->unsignedInteger('iar_quantity')->default(0);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('line_remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('purchase_order_line_id', 'iar_lines_po_line_fk')
                ->references('id')
                ->on('purchase_order_lines')
                ->restrictOnDelete();
            $table->foreign('acquisition_paperwork_line_id', 'iar_lines_pr_line_fk')
                ->references('id')
                ->on('acquisition_paperwork_lines')
                ->restrictOnDelete();

            $table->unique(
                ['inspection_acceptance_report_id', 'purchase_order_line_id'],
                'iar_line_po_line_unique',
            );
            $table->index('purchase_order_line_id', 'iar_lines_po_line_idx');
            $table->index('acquisition_paperwork_line_id', 'iar_lines_pr_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_acceptance_report_lines');
    }
};
