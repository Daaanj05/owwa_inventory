<?php

namespace Database\Seeders;

use App\Models\ReferenceSeries;
use Illuminate\Database\Seeder;

class ReferenceSeriesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'type' => ReferenceSeries::TYPE_ISSUANCE,
                'name' => 'Issuance (RIS)',
                'prefix' => 'RIS',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_TRANSFER,
                'name' => 'Transfer (PTR)',
                'prefix' => 'PTR',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_DISPOSAL,
                'name' => 'Disposal',
                'prefix' => 'DSP',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_REQUISITION,
                'name' => 'Requisition',
                'prefix' => 'REQ',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ACQUISITION,
                'name' => 'Acquisition',
                'prefix' => 'ACQ',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
        ];

        foreach ($defaults as $row) {
            ReferenceSeries::query()->updateOrCreate(
                ['type' => $row['type']],
                $row
            );
        }
    }
}
