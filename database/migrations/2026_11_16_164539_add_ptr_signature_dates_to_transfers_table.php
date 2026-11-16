<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->date('date_of_approval')->nullable()->after('received_by_designation');
            $table->date('date_released')->nullable()->after('date_of_approval');
            $table->date('date_received')->nullable()->after('date_released');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['date_of_approval', 'date_released', 'date_received']);
        });
    }
};
