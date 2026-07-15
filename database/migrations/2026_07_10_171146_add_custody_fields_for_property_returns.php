<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_action_request_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('property_action_request_lines', 'transfer_id')) {
                $table->foreignId('transfer_id')->nullable()->after('disposal_id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('issuances', function (Blueprint $table): void {
            if (! Schema::hasColumn('issuances', 'custody_ended_at')) {
                $table->timestamp('custody_ended_at')->nullable()->after('issued_to');
            }
            if (! Schema::hasColumn('issuances', 'custody_end_type')) {
                $table->string('custody_end_type', 20)->nullable()->after('custody_ended_at');
            }
            if (! Schema::hasColumn('issuances', 'custody_end_reference')) {
                $table->string('custody_end_reference')->nullable()->after('custody_end_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_action_request_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('property_action_request_lines', 'transfer_id')) {
                $table->dropConstrainedForeignId('transfer_id');
            }
        });

        Schema::table('issuances', function (Blueprint $table): void {
            $columns = ['custody_end_reference', 'custody_end_type', 'custody_ended_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('issuances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
