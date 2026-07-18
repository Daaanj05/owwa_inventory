<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acquisition_paperwork_id')
                ->constrained('acquisition_paperwork')
                ->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number')->nullable()->unique();
            $table->string('status', 32)->default('draft');
            $table->date('po_date')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_address')->nullable();
            $table->string('supplier_tin', 32)->nullable();
            $table->string('mode_of_procurement')->nullable();
            $table->string('place_of_delivery')->nullable();
            $table->string('delivery_term')->nullable();
            $table->date('date_of_delivery')->nullable();
            $table->string('payment_term')->nullable();
            $table->text('technical_specifications')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('acquisition_paperwork_id');
            $table->index('status');
            $table->index('po_date');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
