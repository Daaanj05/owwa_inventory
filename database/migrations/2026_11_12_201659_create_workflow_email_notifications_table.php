<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_email_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_action_request_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('physical_inventory_plan_id')->nullable();
            $table->unsignedBigInteger('physical_inventory_plan_line_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 32);
            $table->text('failure_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('physical_inventory_plan_id', 'wen_plan_fk')
                ->references('id')
                ->on('physical_inventory_plans')
                ->nullOnDelete();
            $table->foreign('physical_inventory_plan_line_id', 'wen_plan_line_fk')
                ->references('id')
                ->on('physical_inventory_plan_lines')
                ->nullOnDelete();

            $table->index(['requisition_id', 'created_at'], 'wen_requisition_created_idx');
            $table->index(['property_action_request_id', 'created_at'], 'wen_par_created_idx');
            $table->index(['physical_inventory_plan_id', 'created_at'], 'wen_plan_created_idx');
            $table->index(['user_id', 'created_at'], 'wen_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_email_notifications');
    }
};
