<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_logs', function (Blueprint $table): void {
            $table->timestamp('logged_out_at')->nullable()->after('logged_in_at');
            $table->string('logout_reason', 32)->nullable()->after('logged_out_at');
            $table->timestamp('last_activity_at')->nullable()->after('logout_reason');
            $table->string('session_id', 128)->nullable()->after('last_activity_at');
            $table->timestamp('archived_at')->nullable()->after('session_id');

            $table->index(['user_id', 'logged_in_at']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_logs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'logged_in_at']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'logged_out_at',
                'logout_reason',
                'last_activity_at',
                'session_id',
                'archived_at',
            ]);
        });
    }
};
