<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issuance_batches')) {
            return;
        }

        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->dropUnique(['reference_code']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->unique(['category_slug', 'reference_code']);
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('issuance_batches')) {
            return;
        }

        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->dropUnique(['category_slug', 'reference_code']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->unique('reference_code');
            });
        } catch (\Throwable) {
        }
    }
};
