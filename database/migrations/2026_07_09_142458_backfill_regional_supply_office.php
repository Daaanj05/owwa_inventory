<?php

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offices') || ! Schema::hasColumn('offices', 'is_regional_supply')) {
            return;
        }

        if (Office::query()->active()->where('is_regional_supply', true)->exists()) {
            return;
        }

        $candidate = Office::query()
            ->active()
            ->where('code', 'OWWA-IVA')
            ->first();

        if ($candidate === null) {
            $custodianOfficeIds = User::query()
                ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
                ->whereNotNull('office_id')
                ->distinct()
                ->pluck('office_id');

            if ($custodianOfficeIds->count() === 1) {
                $candidate = Office::query()->active()->find($custodianOfficeIds->first());
            }
        }

        if ($candidate === null) {
            $nonSatelliteOffices = Office::query()
                ->active()
                ->where('is_satellite', false)
                ->orderBy('name')
                ->get();

            if ($nonSatelliteOffices->count() === 1) {
                $candidate = $nonSatelliteOffices->first();
            }
        }

        if ($candidate === null) {
            return;
        }

        $candidate->update(['is_regional_supply' => true]);
    }

    public function down(): void
    {
        // Non-destructive data backfill — no rollback.
    }
};
