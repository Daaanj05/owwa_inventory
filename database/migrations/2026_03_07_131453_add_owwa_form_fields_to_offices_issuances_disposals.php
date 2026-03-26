<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('fund_cluster')->nullable()->after('code');
        });

        Schema::table('issuances', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
            $table->decimal('amount', 12, 2)->nullable()->after('unit_cost');
            $table->string('custodian_printed_name')->nullable()->after('remarks');
            $table->string('accounting_staff_printed_name')->nullable()->after('custodian_printed_name');
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->string('official_receipt_no')->nullable()->after('reason');
            $table->date('sale_date')->nullable()->after('official_receipt_no');
            $table->decimal('sale_amount', 12, 2)->nullable()->after('sale_date');
            $table->string('custodian_printed_name')->nullable()->after('remarks');
            $table->string('approved_by_printed_name')->nullable()->after('custodian_printed_name');
            $table->string('inspection_officer_printed_name')->nullable()->after('approved_by_printed_name');
            $table->string('witness_printed_name')->nullable()->after('inspection_officer_printed_name');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('fund_cluster');
        });

        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'amount', 'custodian_printed_name', 'accounting_staff_printed_name']);
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->dropColumn([
                'official_receipt_no', 'sale_date', 'sale_amount',
                'custodian_printed_name', 'approved_by_printed_name',
                'inspection_officer_printed_name', 'witness_printed_name',
            ]);
        });
    }
};
