<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ucRole = 'authorized_personnel';

        DB::table('requisitions')
            ->where('requisitions.status', 'approved')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('issuances')
                    ->whereColumn('issuances.requisition_id', 'requisitions.id');
            })
            ->whereExists(function ($query) use ($ucRole): void {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'requisitions.requested_by')
                    ->where('users.role', $ucRole);
            })
            ->update(['status' => 'pending']);

        DB::table('requisitions')
            ->whereIn('status', ['approved', 'partially_fulfilled', 'fulfilled'])
            ->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        // One-way data normalization; prior granular statuses cannot be restored reliably.
    }
};
