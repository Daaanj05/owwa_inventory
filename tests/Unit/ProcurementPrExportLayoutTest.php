<?php

namespace Tests\Unit;

use App\Support\OwwaCellMapping;
use App\Support\OwwaExportStandards;
use App\Support\OwwaSpreadsheetLayoutHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ProcurementPrExportLayoutTest extends TestCase
{
    public function test_pr_min_wrap_lines_for_expansion_honors_config_one(): void
    {
        $detail = config('owwa_cell_maps.PR.detail');

        $this->assertSame(1, OwwaExportStandards::minWrapLinesForExpansion($detail));
        $this->assertTrue(OwwaExportStandards::uniformDataRowHeight($detail));
    }

    public function test_pr_signatures_include_designation_cells(): void
    {
        $signatures = OwwaCellMapping::form('PR')['signatures'];

        $this->assertSame('B39', $signatures['requested_by']);
        $this->assertSame('D39', $signatures['approved_by']);
        $this->assertSame('B40', $signatures['requested_by_designation']);
        $this->assertSame('D40', $signatures['approved_by_designation']);
    }

    public function test_pr_apply_signatures_writes_designation_values(): void
    {
        $values = [];

        OwwaCellMapping::applySignatures($values, 'PR', [
            'requested_by' => 'Maria Santos',
            'approved_by' => 'Roberto Cruz',
            'requested_by_designation' => 'Administrative Aide',
            'approved_by_designation' => 'Regional Director',
        ]);

        $this->assertSame('Maria Santos', $values['B39']);
        $this->assertSame('Roberto Cruz', $values['D39']);
        $this->assertSame('Administrative Aide', $values['B40']);
        $this->assertSame('Regional Director', $values['D40']);
    }

    public function test_pr_long_description_expands_with_min_wrap_lines_one(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->setCellValue('C11', str_repeat('Long description for wrap expansion testing ', 8));
        $baseHeight = 15.0;

        $estimated = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            11,
            ['C'],
            $baseHeight,
            OwwaExportStandards::minWrapLinesForExpansion(config('owwa_cell_maps.PR.detail')),
        );

        $this->assertGreaterThan($baseHeight, $estimated);
    }
}
