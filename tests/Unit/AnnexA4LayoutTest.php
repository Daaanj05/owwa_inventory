<?php

namespace Tests\Unit;

use App\Support\AnnexA4Layout;
use App\Support\OwwaCellMapping;
use Tests\TestCase;

class AnnexA4LayoutTest extends TestCase
{
    public function test_master_template_sheet_name_is_reg_spi(): void
    {
        $this->assertSame('RegSPI', AnnexA4Layout::templateSheetName());
    }

    public function test_ledger_layout_values_match_config(): void
    {
        $ledger = OwwaCellMapping::form('ANNEX_A4')['ledger'];

        $this->assertSame((int) $ledger['start_row'], AnnexA4Layout::ledgerStartRow());
        $this->assertSame((int) $ledger['style_row'], AnnexA4Layout::ledgerStyleRow());
        $this->assertSame(AnnexA4Layout::ledgerStartRow() - 1, AnnexA4Layout::headerRowCount());
        $this->assertSame((string) $ledger['highest_column'], AnnexA4Layout::highestColumn());
    }
}
