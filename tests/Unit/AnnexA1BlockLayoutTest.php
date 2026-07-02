<?php

namespace Tests\Unit;

use App\Support\AnnexA1BlockLayout;
use Tests\TestCase;

class AnnexA1BlockLayoutTest extends TestCase
{
    public function test_uses_dynamic_block_heights(): void
    {
        $this->assertSame(7, AnnexA1BlockLayout::owwaHeaderRows());
        $this->assertSame(14, AnnexA1BlockLayout::headerSectionRows());
        $this->assertSame(5, AnnexA1BlockLayout::blankStyleRows());
        $this->assertSame(20, AnnexA1BlockLayout::blockHeight(0));
        $this->assertSame(22, AnnexA1BlockLayout::blockHeight(2));
        $this->assertSame(23, AnnexA1BlockLayout::blockHeight(3));
        $this->assertSame(25, AnnexA1BlockLayout::blockHeight(5));
        $this->assertSame(26, AnnexA1BlockLayout::blockHeight(6));
        $this->assertSame(8, AnnexA1BlockLayout::entityRowForBlockStart(1));
        $this->assertSame(15, AnnexA1BlockLayout::ledgerStartRowForBlockStart(1));
        $this->assertSame([1, 21], AnnexA1BlockLayout::blockStartRows([0, 0]));
        $this->assertSame(7, AnnexA1BlockLayout::ledgerRowsForTransactionCount(2));
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
        ], 1);
        AnnexA1BlockLayout::applyHeader($values, [
            'entity_name' => 'RWO IV-A',
            'fund_cluster' => '01',
            'property_type' => 'SPORTS EQUIPMENT',
            'property_number' => 'SEM-101',
            'description' => 'Spin bike',
        ], 21);

        $this->assertSame('Description : Weight bench', $values['A12']);
        $this->assertSame('Description : Spin bike', $values['A32']);
        $this->assertSame('Semi-expendable Property Number: SEM-100', $values['K11']);
        $this->assertSame('Semi-expendable Property Number: SEM-101', $values['K31']);
    }
}
