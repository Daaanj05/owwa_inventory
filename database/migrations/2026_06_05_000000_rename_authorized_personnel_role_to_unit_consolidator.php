<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'authorized_personnel')
            ->update(['role' => 'unit_consolidator']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'unit_consolidator')
            ->update(['role' => 'authorized_personnel']);
    }
};
