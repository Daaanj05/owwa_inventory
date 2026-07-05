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
use App\Support\ItemPropertyClass;
use App\Support\OwwaCellMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

        $this->assertSame('VEHICLE EQUIPMENT ', $sheet['sheetName']);
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
            fn (array $tab): array => [$tab['sheetName'] => $tab['cellValues']['B5'] ?? null],
        );

        $this->assertSame('INFORMATION & COMMUNICATION TECHNOLOGY', $b5BySheet->get('ICT'));
        $this->assertSame('SPORTS EQUIPMENT', $b5BySheet->get('SPORTS EQUIPMENT'));

        $spreadsheet = app(\App\Services\OwwaTemplateExportService::class)->buildRpcspPhysicalCountSpreadsheet($tabs);
        $this->assertNotNull($spreadsheet->getSheetByName('ICT'));
        $this->assertNotNull($spreadsheet->getSheetByName('SPORTS EQUIPMENT'));
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

    public function test_physical_count_signature_row_offsets_after_row_expansion(): void
    {
        $extra = 4;

        $this->assertSame(
            'C42',
            OwwaCellMapping::physicalCountSignatureCell('RPCSP', 'certified_by', $extra),
        );
        $this->assertSame(
            'D42',
            OwwaCellMapping::physicalCountSignatureCell('RPCPPE', 'certified_by', $extra),
        );
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
