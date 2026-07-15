<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->timestamp('endorsed_at')->nullable()->after('approved_at');
            $table->foreignId('endorsed_by')->nullable()->after('endorsed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('endorsed_by');
            $table->string('fulfillment_summary')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropColumn(['endorsed_at', 'closed_at', 'fulfillment_summary']);
        });
    }
};
