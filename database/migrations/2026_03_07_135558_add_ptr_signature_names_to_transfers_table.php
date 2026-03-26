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
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('approved_by_printed_name')->nullable()->after('remarks');
            $table->string('released_by_printed_name')->nullable()->after('approved_by_printed_name');
            $table->string('received_by_printed_name')->nullable()->after('released_by_printed_name');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['approved_by_printed_name', 'released_by_printed_name', 'received_by_printed_name']);
        });
    }
};
