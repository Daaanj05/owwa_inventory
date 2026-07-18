<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisitions', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('acquisition_paperwork_line_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('inspection_acceptance_report_id')
                ->nullable()
                ->after('purchase_order_line_id')
                ->constrained('inspection_acceptance_reports')
                ->nullOnDelete();
            $table->foreignId('inspection_acceptance_report_line_id')
                ->nullable()
                ->after('inspection_acceptance_report_id')
                ->constrained('inspection_acceptance_report_lines', 'id', 'acquisitions_iar_line_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acquisitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspection_acceptance_report_line_id');
            $table->dropConstrainedForeignId('inspection_acceptance_report_id');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
