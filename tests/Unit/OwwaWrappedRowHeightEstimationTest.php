<?php

namespace Tests\Unit;

use App\Support\OwwaExportStandards;
use App\Support\OwwaSpreadsheetLayoutHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class OwwaWrappedRowHeightEstimationTest extends TestCase
{
    public function test_single_line_text_keeps_base_row_height(): void
    {
        $sheet = $this->worksheetWithCell('G12', 'Supply Custodian', 20.0);
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['G'],
            $baseHeight,
        );

        $this->assertSame($baseHeight, $height);
    }

    public function test_long_office_name_expands_modestly_in_narrow_column(): void
    {
        $sheet = $this->worksheetWithCell('G12', 'OWWA Regional Office IV-A', 8.0);
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['G'],
            $baseHeight,
        );

        $this->assertGreaterThan($baseHeight, $height);
        $this->assertLessThanOrEqual($baseHeight * OwwaExportStandards::maxWrapLines(), $height);
        $this->assertSame(45.0, $height);
    }

    public function test_long_description_expands_modestly(): void
    {
        $sheet = $this->worksheetWithCell(
            'D12',
            'Service van tools – Vehicle equipment and maintenance kit',
            10.0,
        );
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['D'],
            $baseHeight,
        );

        $this->assertGreaterThan($baseHeight, $height);
        $this->assertLessThanOrEqual($baseHeight * OwwaExportStandards::maxWrapLines(), $height);
    }

    public function test_qty_column_outside_wrap_set_does_not_expand_row(): void
    {
        $sheet = $this->worksheetWithCell('F12', '123456789012345', 6.0);
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['B', 'C', 'D', 'G', 'I', 'K', 'O'],
            $baseHeight,
        );

        $this->assertSame($baseHeight, $height);
    }

    public function test_merged_cell_width_sums_participating_columns(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->mergeCells('G12:H12');
        $sheet->getColumnDimension('G')->setWidth(6.0);
        $sheet->getColumnDimension('H')->setWidth(6.0);
        $sheet->setCellValue('G12', 'OWWA Regional Office IV-A');

        $width = OwwaSpreadsheetLayoutHelper::effectiveColumnWidth($sheet, 'G', 12);

        $this->assertSame(12.0, $width);

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['G'],
            15.0,
        );

        $this->assertSame(30.0, $height);
    }

    public function test_max_wrap_lines_caps_runaway_height(): void
    {
        $sheet = $this->worksheetWithCell('G12', str_repeat('A', 500), 5.0);
        $baseHeight = 15.0;
        $maxLines = OwwaExportStandards::maxWrapLines();

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['G'],
            $baseHeight,
        );

        $this->assertSame($baseHeight * $maxLines, $height);
    }

    public function test_two_line_wrap_estimate_keeps_base_height_when_min_wrap_lines_is_three(): void
    {
        $sheet = $this->worksheetWithCell('O12', 'Damaged beyond repair', 17.0);
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['O'],
            $baseHeight,
            3,
        );

        $this->assertSame($baseHeight, $height);
    }

    public function test_three_line_wrap_estimate_expands_when_min_wrap_lines_is_three(): void
    {
        $sheet = $this->worksheetWithCell('G12', 'OWWA Regional Office IV-A', 8.0);
        $baseHeight = 15.0;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            12,
            ['G'],
            $baseHeight,
            3,
        );

        $this->assertSame(45.0, $height);
    }

    public function test_chars_per_line_uses_font_aware_capacity(): void
    {
        $this->assertSame(9, OwwaExportStandards::charsPerLineForColumnWidth(8.0));
        $this->assertSame(12, OwwaExportStandards::charsPerLineForColumnWidth(10.0));
    }

    public function test_annex_a4_wrap_text_columns_include_reference_and_property_number(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A4.ledger');

        $columns = OwwaExportStandards::resolveWrapTextColumns($ledger);

        $this->assertSame(['B', 'C', 'D', 'G', 'I', 'K', 'O'], $columns);
        $this->assertContains('B', $columns);
        $this->assertContains('C', $columns);
    }

    public function test_annex_a4_min_wrap_lines_for_expansion_is_two(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A4.ledger');

        $this->assertSame(2, OwwaExportStandards::minWrapLinesForExpansion($ledger));
    }

    public function test_sc_and_pc_wrap_text_columns_expand_long_text(): void
    {
        $sc = config('owwa_cell_maps.SC.ledger');
        $pc = config('owwa_cell_maps.PC.ledger');

        $this->assertSame(['B', 'E', 'G'], OwwaExportStandards::resolveWrapTextColumns($sc));
        $this->assertSame(['C', 'F', 'J'], OwwaExportStandards::resolveWrapTextColumns($pc));
        $this->assertTrue(OwwaExportStandards::uniformDataRowHeight($sc));
        $this->assertTrue(OwwaExportStandards::uniformDataRowHeight($pc));
    }

    public function test_annex_a1_property_number_expands_in_template_column_g_width(): void
    {
        $sheet = $this->worksheetWithCell('G15', 'SEM-FF-001-001', 10.59765625);
        $baseHeight = 15.75;

        $height = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            15,
            ['G'],
            $baseHeight,
        );

        $this->assertSame(31.5, $height);
    }

    public function test_hyphenated_property_number_estimates_two_lines_at_boundary_capacity(): void
    {
        $charsPerLine = OwwaExportStandards::charsPerLineForColumnWidth(10.59765625);

        $this->assertSame(12, $charsPerLine);
        $this->assertSame(
            2,
            OwwaSpreadsheetLayoutHelper::estimateWrappedLineCount('SEM-FF-001-001', $charsPerLine),
        );
    }

    public function test_annex_a1_wrap_text_columns_include_reference_column(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A1.ledger');

        $columns = OwwaExportStandards::resolveWrapTextColumns($ledger);

        $this->assertSame(['B', 'G', 'I', 'L'], $columns);
        $this->assertContains('B', $columns);
    }

    public function test_annex_a1_min_wrap_lines_for_expansion_is_two(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A1.ledger');

        $this->assertSame(2, OwwaExportStandards::minWrapLinesForExpansion($ledger));
    }

    public function test_annex_a1_uniform_data_row_height_is_enabled(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A1.ledger');

        $this->assertTrue(OwwaExportStandards::uniformDataRowHeight($ledger));
    }

    public function test_annex_a4_uniform_data_row_height_is_enabled(): void
    {
        $ledger = config('owwa_cell_maps.ANNEX_A4.ledger');

        $this->assertTrue(OwwaExportStandards::uniformDataRowHeight($ledger));
    }

    protected function worksheetWithCell(string $coordinate, string $value, float $columnWidth): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $column = preg_replace('/\d+/', '', $coordinate) ?? 'A';
        $sheet->getColumnDimension($column)->setWidth($columnWidth);
        $sheet->setCellValue($coordinate, $value);

        return $sheet;
    }
}
