<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();
            $table->string('count_type', 20);
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('count_date');
            $table->string('inventory_type_label')->nullable();
            $table->string('fund_cluster')->nullable();
            $table->string('accountable_officer_name')->nullable();
            $table->string('accountable_officer_designation')->nullable();
            $table->date('date_of_assumption')->nullable();
            $table->string('certified_by_printed_name')->nullable();
            $table->string('approved_by_printed_name')->nullable();
            $table->string('verified_by_printed_name')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('physical_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('article')->nullable();
            $table->string('description')->nullable();
            $table->string('stock_number')->nullable();
            $table->string('property_number')->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->unsignedInteger('balance_per_card')->default(0);
            $table->unsignedInteger('on_hand_count')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_count_lines');
        Schema::dropIfExists('physical_count_sessions');
    }
};
