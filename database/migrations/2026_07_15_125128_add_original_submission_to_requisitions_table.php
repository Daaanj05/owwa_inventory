<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->json('original_submission')->nullable()->after('purpose');
            $table->timestamp('content_edited_at')->nullable()->after('original_submission');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->dropColumn(['original_submission', 'content_edited_at']);
        });
    }
};
