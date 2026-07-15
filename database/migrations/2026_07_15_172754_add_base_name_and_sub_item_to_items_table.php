<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('base_name')->nullable()->after('name');
            $table->string('sub_item')->nullable()->after('base_name');
        });

        DB::table('items')
            ->whereNull('base_name')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('items')
                        ->where('id', $row->id)
                        ->update(['base_name' => $row->name]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['base_name', 'sub_item']);
        });
    }
};
