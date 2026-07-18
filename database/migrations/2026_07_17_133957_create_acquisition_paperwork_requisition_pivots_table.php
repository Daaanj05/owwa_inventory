<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('acquisition_paperwork_line_requisition_item');
        Schema::dropIfExists('acquisition_paperwork_requisition');

        Schema::create('acquisition_paperwork_requisition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acquisition_paperwork_id');
            $table->foreignId('requisition_id');
            $table->timestamps();

            $table->foreign('acquisition_paperwork_id', 'ap_req_paperwork_fk')
                ->references('id')
                ->on('acquisition_paperwork')
                ->cascadeOnDelete();
            $table->foreign('requisition_id', 'ap_req_requisition_fk')
                ->references('id')
                ->on('requisitions')
                ->cascadeOnDelete();
            $table->unique(
                ['acquisition_paperwork_id', 'requisition_id'],
                'ap_requisition_unique',
            );
        });

        Schema::create('acquisition_paperwork_line_requisition_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acquisition_paperwork_line_id');
            $table->foreignId('requisition_item_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->foreign('acquisition_paperwork_line_id', 'ap_line_req_line_fk')
                ->references('id')
                ->on('acquisition_paperwork_lines')
                ->cascadeOnDelete();
            $table->foreign('requisition_item_id', 'ap_line_req_item_fk')
                ->references('id')
                ->on('requisition_items')
                ->cascadeOnDelete();

            $table->unique(
                ['acquisition_paperwork_line_id', 'requisition_item_id'],
                'ap_line_req_item_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_paperwork_line_requisition_item');
        Schema::dropIfExists('acquisition_paperwork_requisition');
    }
};
