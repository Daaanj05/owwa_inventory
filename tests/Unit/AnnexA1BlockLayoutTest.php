<?php

namespace Tests\Unit;

use App\Support\AnnexA1BlockLayout;
use Tests\TestCase;

class AnnexA1BlockLayoutTest extends TestCase
{
    public function test_uses_uniform_eighteen_row_stride(): void
    {
        $this->assertSame(18, AnnexA1BlockLayout::blockStride());
        $this->assertSame(8, AnnexA1BlockLayout::entityRow(0));
        $this->assertSame(26, AnnexA1BlockLayout::entityRow(1));
        $this->assertSame(15, AnnexA1BlockLayout::ledgerStartRow(0));
        $this->assertSame(33, AnnexA1BlockLayout::ledgerStartRow(1));
    }

    public function test_master_template_sheet_name_is_spc(): void
    {
        $this->assertSame('SPC', AnnexA1BlockLayout::templateSheetName());
    }

    public function test_stacked_blocks_use_offset_header_cells_on_same_sheet(): void
    {
        $values = [];
        AnnexA1BlockLayout::applyHeader($values, [
            'entity_name' => 'RWO IV-A',
            'fund_cluster' => '01',
            'property_type' => 'SPORTS EQUIPMENT',
            'property_number' => 'SEM-100',
            'description' => 'Weight bench',
        ], 0);
        AnnexA1BlockLayout::applyHeader($values, [
            'entity_name' => 'RWO IV-A',
            'fund_cluster' => '01',
            'property_type' => 'SPORTS EQUIPMENT',
            'property_number' => 'SEM-101',
            'description' => 'Spin bike',
        ], 1);

        $this->assertSame('Description : Weight bench', $values['A12']);
        $this->assertSame('Description : Spin bike', $values['A30']);
        $this->assertSame('Semi-expendable Property Number: SEM-100', $values['K11']);
        $this->assertSame('Semi-expendable Property Number: SEM-101', $values['K29']);
    }
}
