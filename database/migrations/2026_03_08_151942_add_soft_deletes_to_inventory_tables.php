<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
