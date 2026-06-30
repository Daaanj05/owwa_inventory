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
                'name' => 'Issuance control no. (RSMI Serial / PAR / ICS)',
                'prefix' => 'ISS',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_TRANSFER,
                'name' => 'Transfer control no. (PTR — Appendix 76)',
                'prefix' => 'PTR',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_DISPOSAL,
                'name' => 'Disposal control no. (WMR / RLSDDP / IIRUP)',
                'prefix' => 'RLSDDP',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_REQUISITION,
                'name' => 'Requisition slip (Appendix 63 RIS)',
                'prefix' => 'RIS',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ACQUISITION_PAPERWORK_PR,
                'name' => 'Purchase request (Appendix 60 PR)',
                'prefix' => 'PR',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ACQUISITION_PAPERWORK_PO,
                'name' => 'Purchase order (Appendix 61 PO)',
                'prefix' => 'PO',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ACQUISITION_PAPERWORK_IAR,
                'name' => 'Inspection and acceptance (Appendix 62 IAR)',
                'prefix' => 'IAR',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ACQUISITION,
                'name' => 'Acquisition / stock receipt reference (SC)',
                'prefix' => 'REF',
                'pattern' => '{Y}-01-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ITEM_CODE_CONSUMABLES,
                'name' => 'Stock number (consumables)',
                'prefix' => 'CON',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ITEM_CODE_PPE,
                'name' => 'Stock number (PPE)',
                'prefix' => 'PPE',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_ITEM_CODE_SEMI,
                'name' => 'Stock number (semi-expendable)',
                'prefix' => 'SE',
                'pattern' => '{prefix}-{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_PROPERTY_NUMBER_PPE,
                'name' => 'Property number (PPE / PAR)',
                'prefix' => '',
                'pattern' => '{Y}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
            [
                'type' => ReferenceSeries::TYPE_PROPERTY_NUMBER_SEMI,
                'name' => 'Inventory item number (semi / ICS)',
                'prefix' => '',
                'pattern' => '{Y}-{seq:4}',
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
