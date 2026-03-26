<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->unique('name');
        });

        Schema::table('departments', function (Blueprint $table): void {
            // Ensure a department name is unique within the same office and fiscal year
            $table->unique(['fiscal_year_id', 'office_id', 'name'], 'departments_fy_office_name_unique');
        });

        Schema::table('requisition_items', function (Blueprint $table): void {
            // Prevent adding the same item twice to the same requisition
            $table->unique(['requisition_id', 'item_id'], 'requisition_items_req_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_fy_office_name_unique');
        });

        Schema::table('requisition_items', function (Blueprint $table): void {
            $table->dropUnique('requisition_items_req_item_unique');
        });
    }
};

