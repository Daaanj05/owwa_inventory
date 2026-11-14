<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->string('ris_approval_status')->nullable()->after('ris_received_by_date');
            $table->json('pending_issue_payload')->nullable()->after('ris_approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->dropColumn(['ris_approval_status', 'pending_issue_payload']);
        });
    }
};
