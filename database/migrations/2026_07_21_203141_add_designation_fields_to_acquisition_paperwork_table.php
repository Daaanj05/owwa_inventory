<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table) {
            $table->string('requested_by_designation')->nullable()->after('requested_by_name');
            $table->string('approved_by_designation')->nullable()->after('approved_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table) {
            $table->dropColumn(['requested_by_designation', 'approved_by_designation']);
        });
    }
};
