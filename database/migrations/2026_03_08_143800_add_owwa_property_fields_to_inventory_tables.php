<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->string('property_number')->nullable()->after('remarks');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('property_number')->nullable()->after('remarks');
            $table->string('condition', 50)->nullable()->after('property_number');
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->string('property_number')->nullable()->after('remarks');
            $table->decimal('acquisition_cost', 12, 2)->nullable()->after('property_number');
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn('property_number');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['property_number', 'condition']);
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->dropColumn(['property_number', 'acquisition_cost']);
        });
    }
};
