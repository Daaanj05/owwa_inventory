<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Support\ItemPropertyClass;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportStandards;
use App\Support\OwwaSpreadsheetLayoutHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSemiExpendableAnnexA4Fixtures;
use Tests\TestCase;

class OwwaAnnexA4BulkExportTest extends TestCase
{
    use CreatesSemiExpendableAnnexA4Fixtures;
    use RefreshDatabase;

    public function test_bulk_annex_a4_export_creates_tabs_for_property_classes_with_issuances(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $template = storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx');
        if (! is_readable($template)) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $office = Office::factory()->create(['name' => 'RWO IV-A', 'fund_cluster' => '01']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $ictItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Router',
            'property_class' => ItemPropertyClass::Ict,
            'item_code' => 'SEM-100',
        ]);
        $officeItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Printer',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'item_code' => 'SEM-200',
        ]);

        foreach ([$ictItem, $officeItem] as $item) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0400',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0401',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $ictItem->id,
            'quantity' => 1,
            'issuance_date' => now()->subDay(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'property_number' => 'SEM-100-001',
            'estimated_useful_life' => '5 yrs',
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0402',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $officeItem->id,
            'quantity' => 1,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'property_number' => 'SEM-200-001',
            'estimated_useful_life' => '3 yrs',
        ]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.annex-a4', [
            'category' => $category->id,
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $tmp = tempnam(sys_get_temp_dir(), 'annex_a4_bulk_');
        file_put_contents($tmp, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tmp);
            $this->assertNull($spreadsheet->getSheetByName('RegSPI'));
            $ictSheet = $spreadsheet->getSheetByName('ICT');
            $officeSheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
            $this->assertNotNull($ictSheet);
            $this->assertNotNull($officeSheet);
            $this->assertStringContainsString('RWO IV-A', (string) $ictSheet->getCell('A6')->getValue());
            $this->assertStringContainsString('INFORMATION & COMMUNICATION TECHNOLOGY', (string) $ictSheet->getCell('A7')->getValue());
            $this->assertSame('2026-01-0401', $ictSheet->getCell('B12')->getValue());
            $this->assertSame('SEM-100-001', $ictSheet->getCell('C12')->getValue());
            $this->assertNotNull($ictSheet->getCell('A12')->getValue());
            $this->assertSame('2026-01-0402', $officeSheet->getCell('B12')->getValue());
            $this->assertNull($officeSheet->getCell('A14')->getValue());
            $this->assertNull($officeSheet->getCell('A33')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_annex_a4_ledger_rows_stay_contiguous_without_mid_table_split(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $template = storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx');
        if (! is_readable($template)) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $entries = [];
        for ($index = 1; $index <= 7; $index++) {
            $entries[] = [
                'date' => sprintf('2026-01-%02d', $index),
                'reference' => sprintf('2026-01-%04d', $index),
                'property_number' => sprintf('SEM-%03d', $index),
                'description' => 'Wall Clock '.$index,
                'estimated_useful_life' => '5 yrs',
                'issued_qty' => 1,
                'issued_office' => 'Admin',
            ];
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'OFFICE EQUIPMENT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'OFFICE EQUIPMENT',
                ],
                'entries' => $entries,
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
        $this->assertNotNull($sheet);
        $this->assertNull($spreadsheet->getSheetByName('RegSPI'));
        $this->assertSame('2026-01-01', $sheet->getCell('A12')->getValue());
        $this->assertSame('2026-01-07', $sheet->getCell('A18')->getValue());
        $this->assertNull($sheet->getCell('A19')->getValue());
        $this->assertNull($sheet->getCell('A38')->getValue());
        $this->assertNull($sheet->getCell('A39')->getValue());
        $this->assertNull($sheet->getCell('D50')->getValue());

        $lastLedgerRow = 12 + 7 + (int) OwwaCellMapping::form('ANNEX_A4')['ledger']['blank_style_rows'] - 1;
        $this->assertSame(Border::BORDER_MEDIUM, $sheet->getStyle('A'.$lastLedgerRow)->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('A39')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(20, OwwaCellMapping::form('ANNEX_A4')['ledger']['blank_style_rows']);
    }

    public function test_annex_a4_config_uses_twenty_blank_style_rows(): void
    {
        $this->assertSame(20, OwwaCellMapping::form('ANNEX_A4')['ledger']['blank_style_rows']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function propertyClassSheetProvider(): array
    {
        return [
            'ict' => [ItemPropertyClass::Ict, 'ICT', 'INFORMATION & COMMUNICATION TECHNOLOGY'],
            'office_equipment' => [ItemPropertyClass::OfficeEquipment, 'OFFICE EQUIPMENT', 'OFFICE EQUIPMENT'],
            'furnitures_fixtures' => [ItemPropertyClass::FurnituresFixtures, 'F&F', 'FURNITURES & FIXTURES'],
            'sports_equipment' => [ItemPropertyClass::SportsEquipment, 'SPORTS EQUIPMENT', 'SPORTS EQUIPMENT'],
            'medical_equipment' => [ItemPropertyClass::MedicalEquipment, 'MEDICAL EQUIPMENT', 'MEDICAL EQUIPMENT'],
            'vehicle_equipment' => [ItemPropertyClass::VehicleEquipment, 'VEHICLE EQUIPMENT', 'VEHICLE EQUIPMENT'],
        ];
    }

    #[DataProvider('propertyClassSheetProvider')]
    public function test_annex_a4_bulk_export_creates_sheet_for_each_property_class(
        string $propertyClass,
        string $expectedSheetName,
        string $expectedPropertyTypeLabel,
    ): void {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $fixture = $this->createSemiItemWithIssuance($propertyClass);

        $response = $this->actingAs($fixture['custodian'])->get(route('owwa.export.bulk.annex-a4', [
            'category' => $fixture['category']->id,
        ]));

        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'annex_a4_class_');
        file_put_contents($tmp, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tmp);
            $this->assertNull($spreadsheet->getSheetByName('RegSPI'));
            $sheet = $spreadsheet->getSheetByName($expectedSheetName);
            $this->assertNotNull($sheet, "Expected sheet [{$expectedSheetName}] for {$propertyClass}");
            $this->assertStringContainsString($expectedPropertyTypeLabel, (string) $sheet->getCell('A7')->getValue());
            $this->assertSame($fixture['issuance']->reference_code, $sheet->getCell('B12')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_annex_a4_blank_padding_rows_have_uniform_height(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'ICT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => 'Keyboard',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'RWO IV-A',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $blankStart = 13;
        $blankEnd = 12 + 1 + (int) OwwaCellMapping::form('ANNEX_A4')['ledger']['blank_style_rows'];
        $referenceHeight = $sheet->getRowDimension($blankStart)->getRowHeight();

        for ($row = $blankStart + 1; $row <= $blankEnd; $row++) {
            $this->assertSame(
                $referenceHeight,
                $sheet->getRowDimension($row)->getRowHeight(),
                "Blank padding row {$row} should match row {$blankStart} height",
            );
        }
    }

    public function test_annex_a4_no_medium_border_except_last_ledger_row(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $entryCount = 1;
        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'ICT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => 'Keyboard',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'RWO IV-A',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $startRow = 12;
        $lastLedgerRow = $startRow + $entryCount + (int) OwwaCellMapping::form('ANNEX_A4')['ledger']['blank_style_rows'] - 1;

        for ($row = $startRow; $row < $lastLedgerRow; $row++) {
            $this->assertSame(
                Border::BORDER_THIN,
                $sheet->getStyle('A'.$row)->getBorders()->getBottom()->getBorderStyle(),
                "Row {$row} should have thin bottom border",
            );
        }

        $this->assertSame(Border::BORDER_MEDIUM, $sheet->getStyle('A'.$lastLedgerRow)->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('A'.($lastLedgerRow + 1))->getBorders()->getBottom()->getBorderStyle());
    }

    public function test_annex_a4_short_issued_office_keeps_standard_data_row_height(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = $this->buildAnnexA4SheetWithEntry([
            'sheetName' => 'ICT',
            'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
            'issued_office' => 'Supply Custodian',
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $this->assertDataRowHeightIsNotExpanded($sheet, 12);
    }

    public function test_annex_a4_long_office_name_keeps_standard_height_until_three_lines(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = $this->buildAnnexA4SheetWithEntry([
            'sheetName' => 'ICT',
            'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
            'issued_office' => 'OWWA Regional Office IV-A Regional Operations',
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $this->assertDataRowHeightIsNotExpanded($sheet, 12);
        $this->assertTrue($sheet->getStyle('G12')->getAlignment()->getWrapText());
    }

    public function test_annex_a4_disposal_row_with_remarks_keeps_standard_height(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'OFFICE EQUIPMENT',
                'header' => [
                    'entity_name' => 'OWWA Regional Office IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'OFFICE EQUIPMENT',
                ],
                'entries' => [
                    [
                        'property_number' => 'SEM-004',
                        'description' => 'Wall Clock',
                        'disposed_qty' => 1,
                        'remarks' => 'Damaged beyond repair',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('OFFICE EQUIPMENT');
        $this->assertNotNull($sheet);

        $this->assertDataRowHeightIsNotExpanded($sheet, 12);
        $this->assertSame('Wall Clock', $sheet->getCell('D12')->getValue());
        $this->assertSame('Damaged beyond repair', $sheet->getCell('O12')->getValue());
        $this->assertTrue($sheet->getStyle('O12')->getAlignment()->getWrapText());
    }

    public function test_annex_a4_very_long_description_expands_at_three_lines(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = $this->buildAnnexA4SheetWithEntry([
            'sheetName' => 'VEHICLE EQUIPMENT ',
            'property_type' => 'VEHICLE EQUIPMENT',
            'description' => str_repeat('Maintenance supplies kit section ', 4),
            'issued_office' => 'Supply Custodian',
        ]);

        $sheet = $spreadsheet->getSheetByName('VEHICLE EQUIPMENT')
            ?? $spreadsheet->getSheetByName('VEHICLE EQUIPMENT ');
        $this->assertNotNull($sheet);

        $standardHeight = $sheet->getRowDimension(13)->getRowHeight();
        $dataRowHeight = $sheet->getRowDimension(12)->getRowHeight();

        $this->assertGreaterThan($standardHeight, $dataRowHeight);
        $this->assertLessThanOrEqual(
            $standardHeight * OwwaExportStandards::maxWrapLines(),
            $dataRowHeight,
        );
        $this->assertTrue($sheet->getStyle('D12')->getAlignment()->getWrapText());
    }

    public function test_annex_a4_mixed_row_heights_stay_proportional(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'ICT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => 'Router',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'Supply Custodian',
                    ],
                    [
                        'date' => '2026-07-02',
                        'reference' => '2026-07-0002',
                        'property_number' => 'SEM-002',
                        'description' => 'Whiteboard',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'OWWA Regional Office IV-A',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $shortRowHeight = $this->resolvedDataRowHeight($sheet, 12);
        $longOfficeRowHeight = $this->resolvedDataRowHeight($sheet, 13);

        $this->assertEqualsWithDelta($shortRowHeight, $longOfficeRowHeight, 0.5);
    }

    public function test_annex_a4_uniform_data_row_height_matches_tallest_entry(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'ICT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => 'Router',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'Supply Custodian',
                    ],
                    [
                        'date' => '2026-07-02',
                        'reference' => '2026-07-0002',
                        'property_number' => 'SEM-002',
                        'description' => str_repeat('Maintenance supplies kit section ', 4),
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'Supply Custodian',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $firstDataRowHeight = $this->resolvedDataRowHeight($sheet, 12);
        $secondDataRowHeight = $this->resolvedDataRowHeight($sheet, 13);
        $blankRowHeight = $sheet->getRowDimension(14)->getRowHeight();

        $this->assertEqualsWithDelta($firstDataRowHeight, $secondDataRowHeight, 0.5);
        $this->assertGreaterThan($blankRowHeight, $firstDataRowHeight);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function annexA4RowHeightTabProvider(): array
    {
        return [
            'ict' => ['ICT', 'INFORMATION & COMMUNICATION TECHNOLOGY'],
            'office_equipment' => ['OFFICE EQUIPMENT', 'OFFICE EQUIPMENT'],
            'vehicle_equipment' => ['VEHICLE EQUIPMENT', 'VEHICLE EQUIPMENT'],
        ];
    }

    #[DataProvider('annexA4RowHeightTabProvider')]
    public function test_annex_a4_row_height_rules_apply_consistently_across_tabs(
        string $sheetName,
        string $propertyType,
    ): void {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $shortOfficeSpreadsheet = $this->buildAnnexA4SheetWithEntry([
            'sheetName' => $sheetName,
            'property_type' => $propertyType,
            'issued_office' => 'Supply Custodian',
        ]);
        $shortSheet = $shortOfficeSpreadsheet->getSheetByName($sheetName);
        $this->assertNotNull($shortSheet);
        $this->assertDataRowHeightIsNotExpanded($shortSheet, 12);

        $longOfficeSpreadsheet = $this->buildAnnexA4SheetWithEntry([
            'sheetName' => $sheetName,
            'property_type' => $propertyType,
            'issued_office' => 'OWWA Regional Office IV-A',
        ]);
        $longOfficeSheet = $longOfficeSpreadsheet->getSheetByName($sheetName);
        $this->assertNotNull($longOfficeSheet);
        $this->assertDataRowHeightIsNotExpanded($longOfficeSheet, 12);
    }

    public function test_annex_a4_property_type_header_formatting(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx'))) {
            $this->markTestSkipped('Annex A.4 template is not present in storage/app/templates.');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => 'ICT',
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => 'Keyboard',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => 'RWO IV-A',
                    ],
                ],
            ],
        ]);

        $sheet = $spreadsheet->getSheetByName('ICT');
        $this->assertNotNull($sheet);

        $value = $sheet->getCell('A7')->getValue();
        $this->assertInstanceOf(RichText::class, $value);

        $runs = $value->getRichTextElements();
        $this->assertTrue($runs[0]->getFont()->getBold());
        $this->assertFalse($runs[1]->getFont()->getBold());
        $this->assertSame('Times New Roman', $runs[0]->getFont()->getName());
        $this->assertSame('Times New Roman', $runs[1]->getFont()->getName());
        $this->assertSame(Font::UNDERLINE_NONE, $runs[1]->getFont()->getUnderline());
    }

    /**
     * @param  array{sheetName: string, property_type: string, issued_office?: string, description?: string}  $options
     */
    protected function buildAnnexA4SheetWithEntry(array $options): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        return app(OwwaTemplateExportService::class)->buildAnnexA4Spreadsheet([
            [
                'sheetName' => $options['sheetName'],
                'header' => [
                    'entity_name' => 'RWO IV-A',
                    'fund_cluster' => '01',
                    'property_type' => $options['property_type'],
                ],
                'entries' => [
                    [
                        'date' => '2026-07-01',
                        'reference' => '2026-07-0001',
                        'property_number' => 'SEM-001',
                        'description' => $options['description'] ?? 'Router',
                        'estimated_useful_life' => '5 yrs',
                        'issued_qty' => 1,
                        'issued_office' => $options['issued_office'] ?? 'RWO IV-A',
                    ],
                ],
            ],
        ]);
    }

    protected function assertDataRowHeightIsNotExpanded(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row): void
    {
        $height = $sheet->getRowDimension($row)->getRowHeight();
        $standardHeight = OwwaSpreadsheetLayoutHelper::resolveLedgerRowHeight(
            $sheet,
            (int) OwwaCellMapping::form('ANNEX_A4')['ledger']['start_row'],
        );

        $this->assertEqualsWithDelta(
            $standardHeight,
            $height,
            0.5,
            "Row {$row} should stay at standard height, got {$height}",
        );
    }

    protected function resolvedDataRowHeight(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row): float
    {
        $height = $sheet->getRowDimension($row)->getRowHeight();

        if ($height > 0) {
            return $height;
        }

        return OwwaExportStandards::ledgerRowHeight();
    }
}
