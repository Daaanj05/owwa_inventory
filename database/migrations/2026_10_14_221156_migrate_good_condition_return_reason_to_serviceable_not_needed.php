<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('property_action_requests')
            ->where('action_type', 'return')
            ->where('reason_code', 'good_condition')
            ->update(['reason_code' => 'serviceable_not_needed']);
    }

    public function down(): void
    {
        DB::table('property_action_requests')
            ->where('action_type', 'return')
            ->where('reason_code', 'serviceable_not_needed')
            ->update(['reason_code' => 'good_condition']);
    }
};
