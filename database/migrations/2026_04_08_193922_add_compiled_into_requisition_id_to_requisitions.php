<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('compiled_into_requisition_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('requisitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compiled_into_requisition_id');
        });
    }
};
