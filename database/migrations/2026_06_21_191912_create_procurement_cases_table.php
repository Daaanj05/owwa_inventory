<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();
            $table->foreignId('item_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase', 20)->default('pr');
            $table->string('pr_number')->nullable();
            $table->string('po_number')->nullable();
            $table->string('iar_number')->nullable();
            $table->date('pr_date')->nullable();
            $table->date('po_date')->nullable();
            $table->date('iar_date')->nullable();
            $table->text('purpose')->nullable();
            $table->string('supplier')->nullable();
            $table->string('requested_by_name')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('inspection_officer_name')->nullable();
            $table->string('custodian_name')->nullable();
            $table->json('po_data')->nullable();
            $table->json('iar_data')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('pr_completed_at')->nullable();
            $table->timestamp('po_completed_at')->nullable();
            $table->timestamp('iar_completed_at')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_cases');
    }
};
