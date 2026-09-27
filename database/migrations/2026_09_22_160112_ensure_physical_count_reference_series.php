<?php

use App\Models\ReferenceSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $series = [
            [
                'type' => ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCI,
                'name' => 'Physical count reference (RPCI)',
                'prefix' => 'RPCI',
            ],
            [
                'type' => ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCPPE,
                'name' => 'Physical count reference (RPCPPE)',
                'prefix' => 'RPCPPE',
            ],
            [
                'type' => ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCSP,
                'name' => 'Physical count reference (RPCSP)',
                'prefix' => 'RPCSP',
            ],
        ];

        foreach ($series as $row) {
            if (DB::table('reference_series')->where('type', $row['type'])->exists()) {
                continue;
            }

            DB::table('reference_series')->insert([
                'type' => $row['type'],
                'name' => $row['name'],
                'prefix' => $row['prefix'],
                'pattern' => '{prefix}-{Y}-{m}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('reference_series')
            ->whereIn('type', [
                ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCI,
                ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCPPE,
                ReferenceSeries::TYPE_PHYSICAL_COUNT_RPCSP,
            ])
            ->delete();
    }
};
