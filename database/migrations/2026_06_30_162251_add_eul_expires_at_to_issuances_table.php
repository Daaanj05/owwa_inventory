<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->date('eul_expires_at')->nullable()->after('estimated_useful_life');
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn('eul_expires_at');
        });
    }
};
