<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('physical_inventory_plans')
            ->whereIn('status', ['draft', 'approved'])
            ->update(['status' => 'scheduled']);
    }

    public function down(): void
    {
        DB::table('physical_inventory_plans')
            ->where('status', 'scheduled')
            ->update(['status' => 'draft']);
    }
};
