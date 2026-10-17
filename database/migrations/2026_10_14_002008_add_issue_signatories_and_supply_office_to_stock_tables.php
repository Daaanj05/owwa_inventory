<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->string('ris_requested_by_name')->nullable();
            $table->string('ris_requested_by_designation')->nullable();
            $table->date('ris_requested_by_date')->nullable();
            $table->string('ris_approved_by_name')->nullable();
            $table->string('ris_approved_by_designation')->nullable();
            $table->date('ris_approved_by_date')->nullable();
            $table->string('ris_issued_by_name')->nullable();
            $table->string('ris_issued_by_designation')->nullable();
            $table->date('ris_issued_by_date')->nullable();
            $table->string('ris_received_by_name')->nullable();
            $table->string('ris_received_by_designation')->nullable();
            $table->date('ris_received_by_date')->nullable();
        });

        Schema::table('issuances', function (Blueprint $table): void {
            $table->foreignId('issued_from_office_id')->nullable()->after('office_id')->constrained('offices')->nullOnDelete();
            $table->string('received_by_printed_name')->nullable()->after('issued_to_designation');
        });

        Schema::table('issuance_batches', function (Blueprint $table): void {
            $table->string('received_by_printed_name')->nullable()->after('issued_to_designation');
        });

        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->foreignId('stock_opening_balance_id')->nullable()->after('acquisition_id')->constrained('stock_opening_balances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_opening_balance_id');
        });

        Schema::table('issuance_batches', function (Blueprint $table): void {
            $table->dropColumn('received_by_printed_name');
        });

        Schema::table('issuances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issued_from_office_id');
            $table->dropColumn('received_by_printed_name');
        });

        Schema::table('requisitions', function (Blueprint $table): void {
            $table->dropColumn([
                'ris_requested_by_name',
                'ris_requested_by_designation',
                'ris_requested_by_date',
                'ris_approved_by_name',
                'ris_approved_by_designation',
                'ris_approved_by_date',
                'ris_issued_by_name',
                'ris_issued_by_designation',
                'ris_issued_by_date',
                'ris_received_by_name',
                'ris_received_by_designation',
                'ris_received_by_date',
            ]);
        });
    }
};
