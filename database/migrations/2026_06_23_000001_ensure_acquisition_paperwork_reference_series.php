<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, array{legacy: string, type: string, name: string, prefix: string}>
     */
    private array $series = [
        [
            'legacy' => 'procurement_pr',
            'type' => 'acquisition_paperwork_pr',
            'name' => 'Purchase request (Appendix 60 PR)',
            'prefix' => 'PR',
        ],
        [
            'legacy' => 'procurement_po',
            'type' => 'acquisition_paperwork_po',
            'name' => 'Purchase order (Appendix 61 PO)',
            'prefix' => 'PO',
        ],
        [
            'legacy' => 'procurement_iar',
            'type' => 'acquisition_paperwork_iar',
            'name' => 'Inspection and acceptance (Appendix 62 IAR)',
            'prefix' => 'IAR',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->series as $row) {
            if (DB::table('reference_series')->where('type', $row['type'])->exists()) {
                continue;
            }

            $legacy = DB::table('reference_series')->where('type', $row['legacy'])->first();

            if ($legacy !== null) {
                DB::table('reference_series')->where('id', $legacy->id)->update([
                    'type' => $row['type'],
                    'name' => $row['name'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('reference_series')->insert([
                'type' => $row['type'],
                'name' => $row['name'],
                'prefix' => $row['prefix'],
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => 'yearly',
                'last_generated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }
};
