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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSemiExpendableAnnexA4Fixtures;
use Tests\TestCase;

class OwwaAnnexA1ExportTest extends TestCase
{
    use CreatesSemiExpendableAnnexA4Fixtures;
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
        $this->assertSame(7, $map['owwa_header_rows']);
        $this->assertSame(5, $map['ledger']['blank_style_rows']);
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

    public function test_stacked_blocks_repeat_owwa_header_on_bulk_sheet(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

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

        $reportService = app(OwwaItemReportService::class);
        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'ICT',
                    'blocks' => $reportService->buildAnnexA1Blocks([
                        ['item' => $itemOne, 'office' => $office, 'office_id' => $office->id],
                        ['item' => $itemTwo, 'office' => $office, 'office_id' => $office->id],
                    ]),
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);
        $this->assertStringContainsString('SEMI-EXPENDABLE PROPERTY CARD', (string) $sheet->getCell('A5')->getValue());
        $secondHeaderRow = AnnexA1BlockLayout::blockStartRows([0, 0])[1] + 4;
        $this->assertStringContainsString(
            'SEMI-EXPENDABLE PROPERTY CARD',
            (string) $sheet->getCell('A'.$secondHeaderRow)->getValue(),
        );
        $this->assertStringContainsString('SEM-101', (string) $sheet->getCell('K31')->getValue());
    }

    public function test_annex_a1_ledger_dates_use_uniform_ten_point_font(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = \App\Models\User::factory()->create(['office_id' => $office->id]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Desk Organizer',
            'item_code' => 'SEM-003',
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);

        foreach (['2026-01-22', '2026-02-01', '2026-02-05'] as $index => $date) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$index,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => $date,
                'recorded_by' => $custodian->id,
            ]);
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'OFFICE EQUIPMENT',
                    'blocks' => [
                        app(OwwaItemReportService::class)->buildAnnexA1Block($item, $office, $office->id),
                    ],
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
        $this->assertNotNull($sheet);

        foreach ([15, 16, 17] as $row) {
            foreach (['A', 'B', 'C', 'J', 'L'] as $column) {
                $this->assertSame(
                    'Times New Roman',
                    $sheet->getStyle($column.$row)->getFont()->getName(),
                    "Font on {$column}{$row} should be Times New Roman",
                );
                $this->assertSame(
                    10.0,
                    $sheet->getStyle($column.$row)->getFont()->getSize(),
                    "Font size on {$column}{$row} should be 10pt",
                );
                $this->assertFalse($sheet->getStyle($column.$row)->getFont()->getBold());
            }
        }
    }

    public function test_two_transactions_plus_five_blanks_equals_seven_ledger_rows(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = \App\Models\User::factory()->create(['office_id' => $office->id]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-010',
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);

        foreach (['2026-01-22', '2026-02-01'] as $index => $date) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$index,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => $date,
                'recorded_by' => $custodian->id,
            ]);
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'OFFICE EQUIPMENT',
                    'blocks' => [
                        app(OwwaItemReportService::class)->buildAnnexA1Block($item, $office, $office->id),
                    ],
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
        $this->assertNotNull($sheet);
        $this->assertSame('2026-02-01', $sheet->getCell('A15')->getValue());
        $this->assertSame('2026-01-22', $sheet->getCell('A16')->getValue());

        foreach ([17, 18, 19, 20, 21] as $row) {
            $this->assertNull($sheet->getCell('A'.$row)->getValue(), "Row {$row} should be blank");
        }
    }

    public function test_annex_a1_single_item_has_five_styled_blank_ledger_rows(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = \App\Models\User::factory()->create(['office_id' => $office->id]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Desk Organizer',
            'item_code' => 'SEM-003',
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => '2026-01-22',
            'recorded_by' => $custodian->id,
        ]);

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'OFFICE EQUIPMENT',
                    'blocks' => [
                        app(OwwaItemReportService::class)->buildAnnexA1Block($item, $office, $office->id),
                    ],
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
        $this->assertNotNull($sheet);
        $this->assertSame('2026-01-22', $sheet->getCell('A15')->getValue());

        foreach ([16, 17, 18, 19, 20] as $row) {
            $this->assertNull($sheet->getCell('A'.$row)->getValue());
            $this->assertSame(10.0, $sheet->getStyle('A'.$row)->getFont()->getSize());
        }
    }

    public function test_stacked_blocks_duplicate_header_drawings(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $itemOne = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-100',
            'property_class' => ItemPropertyClass::Ict,
        ]);

        $itemTwo = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-101',
            'property_class' => ItemPropertyClass::Ict,
        ]);

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'ICT',
                    'blocks' => app(OwwaItemReportService::class)->buildAnnexA1Blocks([
                        ['item' => $itemOne, 'office' => $office, 'office_id' => $office->id],
                        ['item' => $itemTwo, 'office' => $office, 'office_id' => $office->id],
                    ]),
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);
        $this->assertGreaterThanOrEqual(4, $sheet->getDrawingCollection()->count());
    }

    public function test_stacked_ict_items_use_dynamic_block_offsets(): void
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
        $this->assertStringContainsString('SEM-101', (string) ($values['K31'] ?? ''));
        $this->assertSame(8, AnnexA1BlockLayout::entityRow(0));
        $this->assertSame(28, AnnexA1BlockLayout::entityRow(1));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyClassSheetProvider(): array
    {
        return [
            'ict' => [ItemPropertyClass::Ict, 'ICT'],
            'office_equipment' => [ItemPropertyClass::OfficeEquipment, 'OFFICE EQUIPMENT'],
            'furnitures_fixtures' => [ItemPropertyClass::FurnituresFixtures, 'F&F'],
            'sports_equipment' => [ItemPropertyClass::SportsEquipment, 'SPORTS EQUIPMENT'],
            'medical_equipment' => [ItemPropertyClass::MedicalEquipment, 'MEDICAL EQUIPMENT'],
            'vehicle_equipment' => [ItemPropertyClass::VehicleEquipment, 'VEHICLE EQUIPMENT'],
        ];
    }

    #[DataProvider('propertyClassSheetProvider')]
    public function test_single_item_tab_clears_ghost_rows_below_last_block(
        string $propertyClass,
        string $expectedSheetName,
    ): void {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $fixture = $this->createSemiItemWithIssuance($propertyClass);
        $block = app(OwwaItemReportService::class)->buildAnnexA1Block(
            $fixture['item'],
            $fixture['office'],
            $fixture['office']->id,
        );

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => $expectedSheetName,
                    'blocks' => [$block],
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName($expectedSheetName);
        $this->assertNotNull($sheet, "Expected sheet [{$expectedSheetName}] for {$propertyClass}");

        $transactionCount = count($block['transactions']);
        $lastUsedRow = AnnexA1BlockLayout::FIRST_BLOCK_START_ROW
            + AnnexA1BlockLayout::blockHeight($transactionCount)
            - 1;
        $ghostRow = $lastUsedRow + 5;

        $this->assertNull($sheet->getCell('A'.$ghostRow)->getValue());
        $this->assertSame(
            Border::BORDER_NONE,
            $sheet->getStyle('A'.$ghostRow)->getBorders()->getTop()->getBorderStyle(),
            "Row {$ghostRow} should not retain template borders on {$expectedSheetName}",
        );
    }

    public function test_data_row_expands_height_for_wrapped_office_officer_text(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $longOffice = str_repeat('OWWA Regional Office IV-A ', 8);

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA1Spreadsheet(
            [
                [
                    'sheetName' => 'ICT',
                    'blocks' => [
                        [
                            'header' => [
                                'entity_name' => 'RWO IV-A',
                                'fund_cluster' => '01',
                                'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                                'property_number' => 'SEM-ICT-001',
                                'description' => 'Router',
                            ],
                            'transactions' => [
                                [
                                    'date' => '2026-07-01',
                                    'reference' => '2026-07-9000',
                                    'issue_qty' => 1,
                                    'office_officer' => $longOffice,
                                    'balance' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $dataRowHeight = $sheet->getRowDimension(15)->getRowHeight();
        $blankRowHeight = $sheet->getRowDimension(16)->getRowHeight();

        $this->assertTrue(
            $dataRowHeight > $blankRowHeight,
            'Wrapped data row should be taller than the blank padding row beneath it',
        );
        $this->assertTrue($sheet->getStyle('I15')->getAlignment()->getWrapText());
    }
}
