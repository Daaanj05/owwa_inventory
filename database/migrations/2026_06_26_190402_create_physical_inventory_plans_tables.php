<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_inventory_plans', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();
            $table->string('title');
            $table->string('period_label')->nullable();
            $table->date('cut_off_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('committee_chair_name')->nullable();
            $table->string('property_officer_name')->nullable();
            $table->string('accounting_officer_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('coa_submitted_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('physical_inventory_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_inventory_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_category_id')->constrained()->cascadeOnDelete();
            $table->date('planned_date');
            $table->foreignId('physical_count_session_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('last_reminder_type', 10)->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['physical_inventory_plan_id', 'office_id', 'item_category_id'],
                'inventory_plan_line_office_category_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_inventory_plan_lines');
        Schema::dropIfExists('physical_inventory_plans');
    }
};
