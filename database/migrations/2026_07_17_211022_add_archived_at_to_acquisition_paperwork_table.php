<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('received_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
