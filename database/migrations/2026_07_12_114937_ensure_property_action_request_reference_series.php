<?php

use App\Models\ReferenceSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $type = ReferenceSeries::TYPE_PROPERTY_ACTION_REQUEST;

        if (DB::table('reference_series')->where('type', $type)->exists()) {
            return;
        }

        $now = now();

        DB::table('reference_series')->insert([
            'type' => $type,
            'name' => 'Property action request',
            'prefix' => 'PAREQ',
            'pattern' => '{Y}-{m}-{seq:4}',
            'next_sequence' => 1,
            'reset_period' => ReferenceSeries::RESET_YEARLY,
            'last_generated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }
};
