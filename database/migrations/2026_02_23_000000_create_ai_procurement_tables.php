<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_procurement_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at')->useCurrent();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('summary', 1000)->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('status', 32)->default('draft'); // draft, for_approval, approved, archived
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_procurement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_procurement_runs')->cascadeOnDelete();
            $table->string('section', 32)->default('urgent'); // urgent, plan
            $table->string('priority', 16)->nullable();       // High, Medium, Low
            $table->string('item_name', 255);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('office_name', 255)->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->integer('current_stock')->nullable();
            $table->decimal('avg_monthly_usage', 10, 2)->nullable();
            $table->decimal('months_cover', 10, 2)->nullable();
            $table->integer('suggested_qty_min')->nullable();
            $table->integer('suggested_qty_max')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('include_in_request')->default(true);
            $table->timestamps();

            $table->index(['run_id', 'section']);
            $table->index(['item_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_procurement_items');
        Schema::dropIfExists('ai_procurement_runs');
    }
};

