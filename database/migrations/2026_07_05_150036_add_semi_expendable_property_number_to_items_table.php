<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('semi_expendable_property_number', 100)->nullable()->after('item_code');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('semi_expendable_property_number');
        });
    }
};
