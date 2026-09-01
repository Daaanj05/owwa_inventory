<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->foreignId('consolidated_requisition_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('requisitions')
                ->nullOnDelete();
            $table->foreignId('source_endorsement_id')
                ->nullable()
                ->after('consolidated_requisition_id')
                ->constrained('requisition_source_endorsements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_endorsement_id');
            $table->dropConstrainedForeignId('consolidated_requisition_id');
        });
    }
};
