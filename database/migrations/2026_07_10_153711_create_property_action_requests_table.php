<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_action_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();
            $table->string('action_type');
            $table->string('reason_code');
            $table->text('reason_detail')->nullable();
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issuance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('accountable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->foreignId('uc_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uc_approved_at')->nullable();
            $table->text('uc_remarks')->nullable();
            $table->foreignId('sc_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sc_approved_at')->nullable();
            $table->text('sc_remarks')->nullable();
            $table->boolean('offline_approval_received')->default(false);
            $table->date('offline_approval_date')->nullable();
            $table->string('offline_approval_attachment')->nullable();
            $table->text('offline_signatories')->nullable();
            $table->foreignId('disposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('replacement_requisition_id')->nullable()->constrained('requisitions')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_action_requests');
    }
};
