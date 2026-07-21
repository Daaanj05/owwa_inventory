<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Support\ItemPropertyClass;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportStandards;
use App\Support\OwwaSpreadsheetLayoutHelper;
use App\Support\OwwaTemplateLoader;
use App\Support\PhysicalCountPageLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Border;
use ReflectionMethod;
use Tests\TestCase;

class PhysicalCountExportMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_count_template_paths_use_physical_count_folders(): void
    {
        $this->assertStringContainsString(
            'Physical Count',
            (string) OwwaCellMapping::form('RPCPPE')['template'],
        );
        $this->assertStringContainsString(
            'Physical Count',
            (string) OwwaCellMapping::form('RPCSP')['template'],
        );
        $this->assertSame(
            'ppe/Physical Count/Appendix 73 - RPCPPE.xlsx',
            config('owwa_templates.physical_count.ppe.rpcppe.file'),
        );
        $this->assertSame(
            'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xlsx',
            config('owwa_templates.physical_count.consumables.rpci.file'),
        );
        $this->assertSame(
            'Semi-Expendable/Physical Count/Inventory-Annex-A.8-RPCSP - REPORT.xlsx',
            config('owwa_templates.physical_count.semi_expendable.rpcsp.file'),
        );
    }

    public function test_rpci_export_populates_stock_number_unit_value_and_shortage_value(): void
    {
        [$office, $category, $item, $user] = $this->createConsumableFixtures();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-RPC-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 25.50,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'Office Supplies',
        ]);

        $line = PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'stock_number' => $item->item_code,
            'balance_per_card' => 10,
            'on_hand_count' => 8,
        ]);

        $session->setRelation('office', $office);
        $session->setRelation('lines', Collection::make([$line->load('item')]));

        $values = $this->invokeCellValuesForPhysicalCount($session);
        $cols = OwwaCellMapping::detailColumns('RPCI');
        $startRow = OwwaCellMapping::detailRowBase('RPCI');

        $this->assertSame($item->item_code, $values[OwwaCellMapping::columnCell($cols['stock_number'], $startRow)]);
        $this->assertSame(25.50, $values[OwwaCellMapping::columnCell($cols['unit_value'], $startRow)]);
        $this->assertSame(-2, $values[OwwaCellMapping::columnCell($cols['shortage_qty'], $startRow)]);
        $this->assertSame(-51.0, $values[OwwaCellMapping::columnCell($cols['shortage_value'], $startRow)]);
    }

    public function test_physical_count_accountable_officer_keeps_assumption_phrase_for_all_forms(): void
    {
        $cases = [
            PhysicalCountSession::TYPE_RPCI => 'B10',
            PhysicalCountSession::TYPE_RPCPPE => 'C10',
            PhysicalCountSession::TYPE_RPCSP => 'B10',
        ];

        foreach ($cases as $countType => $cell) {
            $office = Office::factory()->create([
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
            ]);
            $category = ItemCategory::factory()->create([
                'name' => match ($countType) {
                    PhysicalCountSession::TYPE_RPCI => 'Consumables',
                    PhysicalCountSession::TYPE_RPCPPE => 'PPE',
                    default => 'Semi-Expendable',
                },
            ]);

            $session = PhysicalCountSession::query()->create([
                'reference_code' => '2026-RPC-ACCT-'.$countType,
                'count_type' => $countType,
                'office_id' => $office->id,
                'item_category_id' => $category->id,
                'count_date' => '2026-06-30',
                'accountable_officer_name' => 'Marita C. Ablis',
                'accountable_officer_designation' => 'Supply Officer',
                'date_of_assumption' => '2026-01-01',
            ]);
            $session->setRelation('office', $office);
            $session->setRelation('lines', Collection::make());

            $values = $this->invokeCellValuesForPhysicalCount($session);

            $this->assertSame(
                'For which Marita C. Ablis, Supply Officer, OWWA Regional Office IV-A is accountable, having assumed such accountability on 2026-01-01.',
                $values[$cell],
                "Failed accountable clause for {$countType}",
            );
        }
    }

    public function test_rpcppe_export_uses_property_number_column(): void
    {
        [$office, $category, $item] = $this->createPpeFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-0002',
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $line = PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'property_number' => 'PPE-2026-0500',
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        $session->setRelation('office', $office);
        $session->setRelation('lines', Collection::make([$line->load('item')]));

        $values = $this->invokeCellValuesForPhysicalCount($session);
        $cols = OwwaCellMapping::detailColumns('RPCPPE');
        $startRow = OwwaCellMapping::detailRowBase('RPCPPE');

        $this->assertSame('PPE-2026-0500', $values[OwwaCellMapping::columnCell($cols['property_number'], $startRow)]);
        $this->assertArrayNotHasKey('stock_number', $cols);
    }

    public function test_rpcsp_resolves_vehicle_equipment_sheet(): void
    {
        $session = new PhysicalCountSession([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'property_class' => ItemPropertyClass::VehicleEquipment,
        ]);

        $sheet = app(OwwaItemReportService::class)->resolvePhysicalCountSheet($session);

        $this->assertSame('TRANSPORTATION EQUIPMENT', $sheet['sheetName']);
    }

    public function test_rpcsp_export_builds_one_tab_per_property_class(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $ictItem = Item::factory()->ict()->create(['item_category_id' => $category->id]);
        $sportsItem = Item::factory()->sportsEquipment()->create(['item_category_id' => $category->id]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'reference_code' => '2026-RPC-0099',
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $ictItem->id,
            'property_number' => 'SE-ICT-001',
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $sportsItem->id,
            'property_number' => 'SE-SPT-001',
            'balance_per_card' => 1,
            'on_hand_count' => 0,
        ]);

        $tabs = app(OwwaItemReportService::class)->buildRpcspPhysicalCountTabs($session->fresh(['office', 'lines.item']));

        $this->assertCount(2, $tabs);

        $b5BySheet = collect($tabs)->mapWithKeys(
            fn (array $tab): array => [$tab['sheetName'] => $tab['propertyClass'] ?? null],
        );

        $this->assertSame(ItemPropertyClass::Ict, $b5BySheet->get('INFORMATION TECHNOLOGY EQUIPMENT'));
        $this->assertSame(ItemPropertyClass::SportsEquipment, $b5BySheet->get('MACHINERY AND EQUIPMENT'));

        $spreadsheet = app(OwwaTemplateExportService::class)->buildRpcspPhysicalCountSpreadsheet(
            $tabs,
            null,
            $session->fresh(['office', 'lines.item']),
        );
        $itSheet = mb_substr('INFORMATION TECHNOLOGY EQUIPMENT', 0, 31);
        $meSheet = mb_substr('MACHINERY AND EQUIPMENT', 0, 31);
        $this->assertNotNull($spreadsheet->getSheetByName($itSheet));
        $this->assertNotNull($spreadsheet->getSheetByName($meSheet));
        $this->assertStringContainsString(
            'INFORMATION',
            (string) $spreadsheet->getSheetByName($itSheet)?->getCell('B5')->getValue(),
        );
    }

    public function test_rpcsp_export_starts_data_after_nothing_to_report_row(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->officeEquipment()->create([
            'item_category_id' => $category->id,
            'name' => 'Paper Cutter Deluxe Model',
            'description' => str_repeat('Heavy duty cutter description ', 6),
            'unit' => 'piece',
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'reference_code' => '2026-RPC-R16',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'article' => $item->name,
            'description' => $item->description,
            'property_number' => 'SPLV-2026-OE-106-001-OWWA-IVA',
            'unit_of_measure' => 'piece',
            'balance_per_card' => 2,
            'on_hand_count' => 2,
        ]);

        $session = $session->fresh(['office', 'lines.item']);
        $tabs = app(OwwaItemReportService::class)->buildRpcspPhysicalCountTabs($session);
        $spreadsheet = app(OwwaTemplateExportService::class)->buildRpcspPhysicalCountSpreadsheet(
            $tabs,
            null,
            $session,
        );

        $sheet = $spreadsheet->getSheet(0);
        $cols = OwwaCellMapping::detailColumns('RPCSP');
        $detailStart = OwwaCellMapping::detailRowBase('RPCSP');

        $this->assertSame(16, $detailStart);
        $this->assertSame('', (string) $sheet->getCell('B15')->getValue());
        $this->assertStringNotContainsString('nothing to report', mb_strtolower((string) $sheet->getCell('B15')->getValue()));
        $this->assertSame(
            'Paper Cutter Deluxe Model',
            (string) $sheet->getCell(OwwaCellMapping::columnCell($cols['article'], $detailStart))->getValue(),
        );
        $this->assertSame(
            'SPLV-2026-OE-106-001-OWWA-IVA',
            (string) $sheet->getCell(OwwaCellMapping::columnCell($cols['property_number'], $detailStart))->getValue(),
        );
        $this->assertSame(
            Border::BORDER_MEDIUM,
            $sheet->getStyle('C'.$detailStart)->getBorders()->getLeft()->getBorderStyle(),
        );
        $this->assertTrue($sheet->getPageSetup()->getFitToPage());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertGreaterThan(
            15.0,
            (float) $sheet->getRowDimension($detailStart)->getRowHeight(),
        );
    }

    public function test_rpcsp_continuous_export_extends_detail_rows_without_sheet_error(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->officeEquipment()->create(['item_category_id' => $category->id]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'reference_code' => '2026-RPC-MULTI',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        $templateDetailRows = PhysicalCountPageLayout::templateDetailRows('RPCSP');
        $this->assertTrue(PhysicalCountPageLayout::isContinuousLayout('RPCSP'));

        for ($i = 1; $i <= $templateDetailRows + 3; $i++) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'property_number' => sprintf('SE-OE-%03d', $i),
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        }

        $session = $session->fresh(['office', 'lines.item']);
        $tabs = app(OwwaItemReportService::class)->buildRpcspPhysicalCountTabs($session);
        $this->assertNotEmpty($tabs);

        $largestTab = collect($tabs)->sortByDesc(
            fn (array $tab): int => ($tab['lines'] ?? collect())->count(),
        )->first();
        $lineCount = ($largestTab['lines'] ?? collect())->count();
        $this->assertGreaterThan($templateDetailRows, $lineCount);

        $spreadsheet = app(OwwaTemplateExportService::class)->buildRpcspPhysicalCountSpreadsheet(
            $tabs,
            null,
            $session,
        );

        $this->assertGreaterThanOrEqual(1, $spreadsheet->getSheetCount());
        $sheet = $spreadsheet->getSheet(0);
        $detailStart = OwwaCellMapping::detailRowBase('RPCSP');
        $cols = OwwaCellMapping::detailColumns('RPCSP');

        $this->assertNotSame('', (string) $sheet->getCell('B16')->getValue());
        $this->assertSame('', (string) $sheet->getCell('B15')->getValue());
        $this->assertStringStartsWith('As at ', (string) $sheet->getCell('B7')->getValue());
        $this->assertStringContainsString('2026-06-30', (string) $sheet->getCell('B7')->getValue());
        $this->assertSame(
            sprintf('SE-OE-%03d', $lineCount),
            (string) $sheet->getCell(
                OwwaCellMapping::columnCell($cols['property_number'], $detailStart + $lineCount - 1),
            )->getValue(),
        );
        $this->assertTrue($sheet->getPageSetup()->getFitToPage());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertSame(
            Border::BORDER_MEDIUM,
            $sheet->getStyle('C16')->getBorders()->getRight()->getBorderStyle(),
        );
        $this->assertSame(
            Border::BORDER_MEDIUM,
            $sheet->getStyle('C16')->getBorders()->getLeft()->getBorderStyle(),
        );

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $this->assertSame('', $drawing->getCoordinates2());
            $this->assertGreaterThan(0, $drawing->getWidth());
            $this->assertLessThanOrEqual(120, $drawing->getWidth());
        }
    }

    public function test_physical_count_exports_keep_as_at_prefix_borders_and_row_expansion(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        foreach (['RPCI', 'RPCPPE'] as $formCode) {
            $this->assertTrue(PhysicalCountPageLayout::isContinuousLayout($formCode));
            $this->assertSame('As at ', OwwaCellMapping::form($formCode)['header']['count_date']['label']);
        }

        [$office, $category, $item] = $this->createConsumableFixtures();
        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-ASAT',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        $templateDetailRows = PhysicalCountPageLayout::templateDetailRows('RPCI');

        for ($index = 1; $index <= $templateDetailRows + 2; $index++) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
                'description' => str_repeat('Long description for wrap and layout ', 8),
            ]);
        }

        $session = $session->fresh(['office', 'lines.item']);
        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            (string) OwwaCellMapping::form('RPCI')['template'],
        );
        $sheet = $spreadsheet->getSheetByName('RPCI');
        $this->assertNotNull($sheet);

        $this->assertSame('As at 2026-06-30', (string) $sheet->getCell('B6')->getValue());
        $this->assertSame(
            'STK-'.($templateDetailRows + 2),
            (string) $sheet->getCell(
                OwwaCellMapping::columnCell(
                    OwwaCellMapping::detailColumns('RPCI')['stock_number'],
                    OwwaCellMapping::detailRowBase('RPCI') + $templateDetailRows + 1,
                ),
            )->getValue(),
        );
        $this->assertSame(
            Border::BORDER_MEDIUM,
            $sheet->getStyle('C16')->getBorders()->getRight()->getBorderStyle(),
        );
        $this->assertTrue($sheet->getPageSetup()->getFitToPage());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertSame('landscape', $sheet->getPageSetup()->getOrientation());

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $this->assertSame('', $drawing->getCoordinates2());
        }

        [$ppeOffice, $ppeCategory, $ppeItem] = $this->createPpeFixtures();
        $ppeSession = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-PPE-ASAT',
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $ppeOffice->id,
            'item_category_id' => $ppeCategory->id,
            'count_date' => '2026-06-30',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        $ppeTemplateRows = PhysicalCountPageLayout::templateDetailRows('RPCPPE');

        for ($index = 1; $index <= $ppeTemplateRows + 2; $index++) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $ppeSession->id,
                'item_id' => $ppeItem->id,
                'property_number' => sprintf('PPE-%03d', $index),
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        }

        $ppeSession = $ppeSession->fresh(['office', 'lines.item']);
        $ppeSpreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $ppeSession,
            'RPCPPE',
            (string) OwwaCellMapping::form('RPCPPE')['template'],
        );
        $ppeSheet = $ppeSpreadsheet->getSheetByName('RPCPPE');
        $this->assertNotNull($ppeSheet);
        $this->assertSame('As at 2026-06-30', (string) $ppeSheet->getCell('C6')->getValue());
        $this->assertSame(
            sprintf('PPE-%03d', $ppeTemplateRows + 2),
            (string) $ppeSheet->getCell(
                OwwaCellMapping::columnCell(
                    OwwaCellMapping::detailColumns('RPCPPE')['property_number'],
                    OwwaCellMapping::detailRowBase('RPCPPE') + $ppeTemplateRows + 1,
                ),
            )->getValue(),
        );
        $this->assertSame(
            Border::BORDER_MEDIUM,
            $ppeSheet->getStyle('D16')->getBorders()->getRight()->getBorderStyle(),
        );
        $this->assertTrue($ppeSheet->getPageSetup()->getFitToPage());
        $this->assertSame(1, $ppeSheet->getPageSetup()->getFitToWidth());
        $this->assertSame('landscape', $ppeSheet->getPageSetup()->getOrientation());
    }

    public function test_physical_count_signatory_cells_use_configured_map(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);

        $session = new PhysicalCountSession([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'count_date' => now(),
            'inventory_type_label' => 'Office Supplies Inventory',
            'accountable_officer_name' => 'Officer A',
            'accountable_officer_designation' => 'Supply Officer',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);
        $session->setRelation('office', $office);
        $session->setRelation('lines', Collection::make());

        $service = app(OwwaItemReportService::class);
        $cells = $service->physicalCountSignatureCells($session);
        $block = OwwaCellMapping::physicalCountSignatureBlock('RPCI');

        $this->assertSame('Certifier', $cells[OwwaCellMapping::columnCell($block['columns']['certified_by'], $block['line_row'])]);
        $this->assertSame('Approver', $cells[OwwaCellMapping::columnCell($block['columns']['approved_by'], $block['line_row'])]);
        $this->assertSame('Verifier', $cells[OwwaCellMapping::columnCell($block['columns']['verified_by'], $block['line_row'])]);
    }

    public function test_rpcsp_signatures_map_to_signature_line_row(): void
    {
        $block = OwwaCellMapping::physicalCountSignatureBlock('RPCSP');

        $this->assertSame(38, $block['line_row']);
        $this->assertSame('C38', OwwaCellMapping::form('RPCSP')['signatures']['certified_by']);
        $this->assertSame('F38', OwwaCellMapping::form('RPCSP')['signatures']['approved_by']);
        $this->assertSame('J38', OwwaCellMapping::form('RPCSP')['signatures']['verified_by']);
    }

    public function test_physical_count_signature_cells_use_fixed_template_rows(): void
    {
        $this->assertSame(
            'C39',
            OwwaCellMapping::physicalCountSignatureCell('RPCI', 'certified_by', 0),
        );
        $this->assertSame(
            'C38',
            OwwaCellMapping::physicalCountSignatureCell('RPCSP', 'certified_by', 0),
        );
        $this->assertSame(
            'D38',
            OwwaCellMapping::physicalCountSignatureCell('RPCPPE', 'certified_by', 0),
        );
    }

    public function test_rpci_continuous_export_extends_detail_rows_on_single_sheet(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-0025',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 25; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $this->assertNotNull($sheet);
        $this->assertNull($spreadsheet->getSheetByName('RPCI Cont.1'));

        $cols = OwwaCellMapping::detailColumns('RPCI');
        $detailStart = OwwaCellMapping::detailRowBase('RPCI');

        $this->assertSame(
            'STK-21',
            $sheet->getCell(OwwaCellMapping::columnCell($cols['stock_number'], $detailStart + 20))->getValue(),
        );
        $this->assertSame(
            'STK-25',
            $sheet->getCell(OwwaCellMapping::columnCell($cols['stock_number'], $detailStart + 24))->getValue(),
        );
        $this->assertSame('Certifier', $sheet->getCell('C43')->getValue());
        $this->assertNull($sheet->getCell('C79')->getValue());

        $printArea = strtoupper((string) $sheet->getPageSetup()->getPrintArea());
        $this->assertStringContainsString('K44', $printArea);
        $this->assertArrayNotHasKey('A41', $sheet->getBreaks());
    }

    public function test_rpci_export_inserts_rows_before_signatures_when_over_21_lines(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-INSERT',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 22; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $detailStart = OwwaCellMapping::detailRowBase('RPCI');

        $this->assertSame(
            'STK-22',
            $sheet->getCell(OwwaCellMapping::columnCell(OwwaCellMapping::detailColumns('RPCI')['stock_number'], $detailStart + 21))->getValue(),
        );
        $this->assertSame(40, PhysicalCountPageLayout::signatureLineRow('RPCI', 22));
    }

    public function test_rpci_continuous_export_has_non_overlapping_merges(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-MERGE',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 25; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $exportService = app(OwwaTemplateExportService::class);
        $spreadsheet = $exportService->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $this->assertNotNull($sheet);
        $this->assertSame([], OwwaSpreadsheetLayoutHelper::overlappingMergePairs($sheet));
        $this->assertGreaterThan(0, count($sheet->getMergeCells()));

        $binary = $exportService->spreadsheetToXlsxBinary($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'rpci-merge-test-').'.xlsx';
        file_put_contents($tempPath, $binary);

        try {
            $reloaded = OwwaTemplateLoader::load($tempPath);
            $reloadedSheet = $reloaded->getSheetByName('RPCI');
            $this->assertNotNull($reloadedSheet);
            $this->assertSame([], OwwaSpreadsheetLayoutHelper::overlappingMergePairs($reloadedSheet));
            $this->assertGreaterThan(0, count($reloadedSheet->getMergeCells()));
            $reloaded->disconnectWorksheets();
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_rpci_export_preserves_template_row_heights(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-0008',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 8; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'article' => 'Pen',
                'description' => 'Blue',
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $templateSpreadsheet = OwwaTemplateLoader::load(
            app(OwwaTemplateExportService::class)->requireTemplateAbsolutePath($templatePath),
        );
        $templateSheet = $templateSpreadsheet->getSheet(0);
        $startRow = OwwaCellMapping::detailRowBase('RPCI');
        $templateHeight = $templateSheet->getRowDimension($startRow)->getRowHeight();
        if ($templateHeight <= 0) {
            $templateHeight = 15.0;
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $exportedHeight = $sheet->getRowDimension($startRow)->getRowHeight();
        if ($exportedHeight <= 0) {
            $exportedHeight = 15.0;
        }

        $this->assertLessThanOrEqual($templateHeight + 0.5, $exportedHeight);
        $this->assertGreaterThan(0, $exportedHeight);
    }

    public function test_rpci_export_widens_columns_or_caps_row_height_for_long_description(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-LONG',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $longDescription = str_repeat('Consumable supply item with extended description text. ', 12);

        $line = PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'stock_number' => 'STK-LONG',
            'description' => $longDescription,
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        $session->setRelation('office', $office);
        $session->setRelation('lines', Collection::make([$line->load('item')]));

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $templateSpreadsheet = OwwaTemplateLoader::load(
            app(OwwaTemplateExportService::class)->requireTemplateAbsolutePath($templatePath),
        );
        $templateSheet = $templateSpreadsheet->getSheet(0);
        $startRow = OwwaCellMapping::detailRowBase('RPCI');
        $templateHeight = $templateSheet->getRowDimension($startRow)->getRowHeight();
        if ($templateHeight <= 0) {
            $templateHeight = 15.0;
        }
        $templateColumnCWidth = $templateSheet->getColumnDimension('C')->getWidth();
        if ($templateColumnCWidth <= 0) {
            $templateColumnCWidth = OwwaExportStandards::defaultColumnWidth('C');
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $exportedHeight = $sheet->getRowDimension($startRow)->getRowHeight();
        if ($exportedHeight <= 0) {
            $exportedHeight = $templateHeight;
        }
        $exportedColumnCWidth = $sheet->getColumnDimension('C')->getWidth();
        if ($exportedColumnCWidth <= 0) {
            $exportedColumnCWidth = OwwaExportStandards::defaultColumnWidth('C');
        }

        $this->assertTrue(
            $exportedColumnCWidth > $templateColumnCWidth || $exportedHeight > $templateHeight,
            'Expected column C to widen or row height to grow for long description.',
        );
        $this->assertGreaterThan($templateHeight, $exportedHeight);
    }

    public function test_rpci_export_expands_row_heights_for_long_descriptions_in_continuous_mode(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        [$office, $category, $item] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-BUDGET',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 5; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'description' => str_repeat('Long wrapped description segment. ', 8),
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $templatePath = (string) OwwaCellMapping::form('RPCI')['template'];
        $templateSpreadsheet = OwwaTemplateLoader::load(
            app(OwwaTemplateExportService::class)->requireTemplateAbsolutePath($templatePath),
        );
        $templateSheet = $templateSpreadsheet->getSheet(0);
        $detailStart = OwwaCellMapping::detailRowBase('RPCI');
        $fallbackHeight = $templateSheet->getRowDimension($detailStart)->getRowHeight();
        if ($fallbackHeight <= 0) {
            $fallbackHeight = 15.0;
        }

        $spreadsheet = app(OwwaTemplateExportService::class)->buildPhysicalCountSpreadsheet(
            $session,
            'RPCI',
            $templatePath,
        );

        $sheet = $spreadsheet->getSheetByName('RPCI');
        $exportedHeight = $sheet->getRowDimension($detailStart)->getRowHeight();
        if ($exportedHeight <= 0) {
            $exportedHeight = $fallbackHeight;
        }

        $this->assertGreaterThan($fallbackHeight, $exportedHeight);
    }

    public function test_rpci_export_includes_all_detail_lines_without_truncation(): void
    {
        [$office, $category, $item, $user] = $this->createConsumableFixtures();

        $session = PhysicalCountSession::query()->create([
            'reference_code' => '2026-RPC-0025',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $lines = Collection::make();

        for ($index = 1; $index <= 25; $index++) {
            $lines->push(PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => 'STK-'.$index,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]));
        }

        $session->setRelation('office', $office);
        $session->setRelation('lines', $lines);

        $values = $this->invokeCellValuesForPhysicalCount($session);
        $cols = OwwaCellMapping::detailColumns('RPCI');
        $startRow = OwwaCellMapping::detailRowBase('RPCI');

        $this->assertSame(
            'STK-25',
            $values[OwwaCellMapping::columnCell($cols['stock_number'], $startRow + 24)],
        );
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: User}
     */
    protected function createConsumableFixtures(): array
    {
        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-010',
            'unit' => 'box',
            'description' => null,
        ]);

        return [$office, $category, $item, $user];
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item}
     */
    protected function createPpeFixtures(): array
    {
        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-001',
            'unit' => 'unit',
        ]);

        return [$office, $category, $item];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function invokeCellValuesForPhysicalCount(PhysicalCountSession $session): array
    {
        $method = new ReflectionMethod(OwwaItemReportService::class, 'cellValuesForPhysicalCount');

        return $method->invoke(app(OwwaItemReportService::class), $session);
    }
}
