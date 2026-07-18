<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_acceptance_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number')->nullable()->unique();
            $table->string('status', 32)->default('draft');
            $table->date('iar_date')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('date_inspected')->nullable();
            $table->date('date_received')->nullable();
            $table->string('inspection_officer_name')->nullable();
            $table->string('custodian_name')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('stock_received_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('purchase_order_id');
            $table->index('status');
            $table->index('iar_date');
            $table->index('archived_at');
            $table->index('stock_received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_acceptance_reports');
    }
};
