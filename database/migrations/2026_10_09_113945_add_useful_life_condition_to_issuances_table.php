<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->string('useful_life_condition', 32)->nullable()->after('eul_expires_at');
            $table->text('useful_life_condition_note')->nullable()->after('useful_life_condition');
            $table->timestamp('useful_life_condition_reported_at')->nullable()->after('useful_life_condition_note');
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn([
                'useful_life_condition',
                'useful_life_condition_note',
                'useful_life_condition_reported_at',
            ]);
        });
    }
};
