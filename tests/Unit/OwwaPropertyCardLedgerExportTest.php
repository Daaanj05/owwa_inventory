<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaPropertyCardLedgerExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pc_ledger_has_five_blank_rows_after_transactions(): void
    {
        $template = storage_path('app/templates/ppe/Accquisition/Appendix 69 - PC.xls');
        if (! is_readable($template)) {
            $this->markTestSkipped('Appendix 69 PC template is not present in storage/app/templates.');
        }

        $office = Office::factory()->create(['name' => 'Test Office', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create(['office_id' => $office->id]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-001',
        ]);

        foreach (['2026-01-01', '2026-02-01'] as $index => $date) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$index,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => $date,
                'recorded_by' => $custodian->id,
            ]);
        }

        $cellValues = app(OwwaItemReportService::class)->cellValuesForPropertyCard($item, $office, $office->id);
        $this->assertArrayHasKey('B12', $cellValues);
        $this->assertArrayHasKey('B13', $cellValues);

        $spreadsheet = app(OwwaTemplateExportService::class)->renderFilledSpreadsheet(
            'ppe/Accquisition/Appendix 69 - PC.xls',
            $cellValues,
        );

        $sheet = $spreadsheet->getSheetByName('PC') ?? $spreadsheet->getActiveSheet();

        $datedRows = 0;
        foreach (range(11, 16) as $row) {
            if (filled($sheet->getCell('B'.$row)->getValue())) {
                $datedRows++;
            }
        }
        $this->assertSame(2, $datedRows);

        foreach ([14, 15, 16, 17, 18] as $row) {
            $this->assertNull($sheet->getCell('B'.$row)->getValue(), "Row {$row} should be blank");
            $this->assertSame('Times New Roman', $sheet->getStyle('B'.$row)->getFont()->getName());
            $this->assertSame(10.0, $sheet->getStyle('B'.$row)->getFont()->getSize());
        }

        $referenceHeight = $sheet->getRowDimension(14)->getRowHeight();
        foreach ([15, 16, 17, 18] as $row) {
            $this->assertSame(
                $referenceHeight,
                $sheet->getRowDimension($row)->getRowHeight(),
                "PC blank padding row {$row} should be uniform",
            );
        }
    }
}
