<?php

namespace Tests\Unit;

use App\Support\OwwaCellMapping;
use Tests\TestCase;

class OwwaCellMappingUsefulLifeTest extends TestCase
{
    public function test_useful_life_column_exists_only_on_ics(): void
    {
        $formsWithUsefulLife = [];

        foreach (OwwaCellMapping::configuredFormCodes() as $formCode) {
            $columns = OwwaCellMapping::detailColumns($formCode);

            if (array_key_exists('useful_life', $columns)) {
                $formsWithUsefulLife[] = $formCode;
            }
        }

        $this->assertSame(['ICS'], $formsWithUsefulLife);
    }

    public function test_par_pc_rpcppe_ptr_and_iirup_do_not_map_useful_life(): void
    {
        foreach (['PAR', 'PC', 'RPCPPE', 'PTR', 'IIRUP'] as $formCode) {
            $columns = OwwaCellMapping::detailColumns($formCode);

            $this->assertArrayNotHasKey('useful_life', $columns, "Form {$formCode} should not map useful_life.");
        }
    }
}
