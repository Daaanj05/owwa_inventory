<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requisition_source_endorsements')) {
            return;
        }

        Schema::create('requisition_source_endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('source_requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->constrained('requisition_items')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('requested_quantity');
            $table->unsignedInteger('endorsed_quantity');
            $table->text('employee_remarks')->nullable();
            $table->timestamps();

            $table->unique(['consolidated_requisition_id', 'requisition_item_id'], 'req_source_endorsements_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_source_endorsements');
    }
};
