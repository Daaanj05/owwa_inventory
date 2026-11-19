<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('physical_inventory_plans')
            ->where('status', 'approved')
            ->update(['status' => 'draft']);
    }

    public function down(): void
    {
        // Approved status was removed; prior approved rows cannot be restored reliably.
    }
};
