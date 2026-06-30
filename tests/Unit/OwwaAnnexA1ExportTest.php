<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Support\AnnexA1BlockLayout;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaAnnexA1ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_report_config_uses_recording_stock_levels_template(): void
    {
        $path = config('owwa_templates.item_report.semi_expendable.annex_a1.file');

        $this->assertStringContainsString('Recording (Stock Levels)', $path);
        $this->assertStringContainsString('Property-Form-Annex-A.1', $path);
    }

    public function test_annex_a1_cell_map_targets_recording_template_layout(): void
    {
        $map = config('owwa_cell_maps.ANNEX_A1');

        $this->assertSame('SPC', $map['template_sheet']);
        $this->assertSame(18, $map['block_stride']);
        $this->assertSame('A8', $map['header']['entity_name']['cell']);
        $this->assertSame('K11', $map['header']['property_number']['cell']);
        $this->assertSame(15, $map['ledger']['start_row']);
        $this->assertSame('C', $map['ledger']['columns']['receipt_qty']);
    }

    public function test_ict_property_class_uses_ict_sheet_label(): void
    {
        $this->assertSame('INFORMATION & COMMUNICATION TECHNOLOGY', ItemPropertyClass::propertyTypeLabel(ItemPropertyClass::Ict));
        $this->assertSame('ICT', ItemPropertyClass::sheetNameForForm('annex_a1', ItemPropertyClass::Ict));
    }

    public function test_annex_a1_export_clears_template_sample_values(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $template = config('owwa_templates.item_report.semi_expendable.annex_a1.file');
        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'ICT',
                    'cellValues' => ['A8' => 'Entity Name : Test Office'],
                ],
            ],
            $template,
        );
        $sheet = $spreadsheet->getSheetByName('ICT');

        $this->assertNotNull($sheet);
        $this->assertNull($spreadsheet->getSheetByName('SPC'));
        $this->assertSame('Entity Name : Test Office', $sheet->getCell('A8')->getValue());
        $this->assertNull($sheet->getCell('D15')->getValue());
        $this->assertNull($sheet->getCell('L15')->getValue());
    }

    public function test_acquisition_annex_a1_maps_receipt_row_to_ledger_columns(): void
    {
        $office = new Office(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item([
            'name' => 'Laptop',
            'item_code' => 'SEM-001',
            'property_class' => ItemPropertyClass::Ict,
        ]);
        $item->setRelation('category', $category);

        $acquisition = new Acquisition([
            'reference_code' => 'ACQ-100',
            'quantity' => 2,
            'unit_cost' => 1500.50,
            'acquisition_date' => now()->parse('2026-01-15'),
            'remarks' => 'Initial stock',
        ]);
        $acquisition->setRelation('item', $item);
        $acquisition->setRelation('office', $office);

        $service = app(OwwaTemplateExportService::class);
        $method = new \ReflectionMethod($service, 'cellValuesForAcquisitionAnnexA1');
        $values = $method->invoke($service, $acquisition);

        $this->assertStringContainsString('RWO IV-A', (string) $values['A8']);
        $this->assertStringContainsString('INFORMATION & COMMUNICATION TECHNOLOGY', (string) $values['A10']);
        $this->assertSame('2026-01-15', $values['A15']);
        $this->assertSame('ACQ-100', $values['B15']);
        $this->assertSame(2, $values['C15']);
        $this->assertSame(1500.50, $values['D15']);
        $this->assertSame(3001.0, $values['E15']);
        $this->assertSame('SEM-001', $values['G15']);
    }

    public function test_item_report_sheet_selection_uses_master_template_sheet_for_loading(): void
    {
        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item([
            'name' => 'Router',
            'property_class' => ItemPropertyClass::MedicalEquipment,
        ]);
        $item->setRelation('category', $category);

        $sheet = app(OwwaItemReportService::class)->resolveItemReportSheet($item, 'annex_a1');

        $this->assertSame(AnnexA1BlockLayout::templateSheetName(), $sheet['sheetName']);
    }

    public function test_stacked_ict_items_use_eighteen_row_block_offsets(): void
    {
        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $itemOne = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Router A',
            'item_code' => 'SEM-100',
            'property_class' => ItemPropertyClass::Ict,
        ]);

        $itemTwo = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Router B',
            'item_code' => 'SEM-101',
            'property_class' => ItemPropertyClass::Ict,
        ]);

        $values = app(OwwaItemReportService::class)->cellValuesForAnnexA1Blocks([
            ['item' => $itemOne, 'office' => $office, 'office_id' => $office->id],
            ['item' => $itemTwo, 'office' => $office, 'office_id' => $office->id],
        ]);

        $this->assertStringContainsString('SEM-100', (string) ($values['K11'] ?? ''));
        $this->assertStringContainsString('SEM-101', (string) ($values['K29'] ?? ''));
        $this->assertSame(8, AnnexA1BlockLayout::entityRow(0));
        $this->assertSame(26, AnnexA1BlockLayout::entityRow(1));
    }
}
