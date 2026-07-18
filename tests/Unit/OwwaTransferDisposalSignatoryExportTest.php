<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\Transfer;
use App\Models\User;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Support\DisposalExportLayout;
use App\Support\OwwaCellMapping;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class OwwaTransferDisposalSignatoryExportTest extends TestCase
{
    public function test_ptr_signatory_cells_use_configured_map(): void
    {
        $fromOffice = new Office(['name' => 'From Office', 'fund_cluster' => '01']);
        $toOffice = new Office(['name' => 'To Office']);
        $item = new Item([
            'item_code' => 'PPE-001',
            'name' => 'Laptop',
        ]);

        $transfer = new Transfer([
            'reference_code' => '2026-01-0099',
            'property_number' => 'PPE-001',
            'quantity' => 1,
            'condition' => 'Serviceable',
            'transfer_date' => now(),
            'approved_by_printed_name' => 'Approver Name',
            'approved_by_designation' => 'Regional Director',
            'released_by_printed_name' => 'Releaser Name',
            'released_by_designation' => 'Supply Officer',
            'received_by_printed_name' => 'Receiver Name',
            'received_by_designation' => 'Custodian',
        ]);
        $transfer->setRelation('item', $item);
        $transfer->setRelation('fromOffice', $fromOffice);
        $transfer->setRelation('toOffice', $toOffice);
        $transfer->setRelation('recordedBy', new User(['name' => 'Recorder']));

        $values = app(OwwaTemplateExportService::class)->cellValuesForTransfer(
            $transfer,
            'ppe/Transfer/Appendix 76 - PTR.xls'
        );

        $signatures = OwwaCellMapping::form('PTR')['signatures'];

        $this->assertSame('Approver Name', $values[$signatures['approved_name']]);
        $this->assertSame('Regional Director', $values[$signatures['approved_designation']]);
        $this->assertSame('Releaser Name', $values[$signatures['released_name']]);
        $this->assertSame('Receiver Name', $values[$signatures['received_name']]);

        $spreadsheet = app(OwwaTemplateExportService::class)->renderFilledSpreadsheet(
            'ppe/Transfer/Appendix 76 - PTR.xls',
            $values,
        );

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertTrue($sheet->getStyle('B53')->getAlignment()->getWrapText());
            $this->assertGreaterThan(13.8, (float) $sheet->getRowDimension(53)->getRowHeight());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_wmr_signatory_cells_use_configured_map(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item(['item_code' => 'CON-001', 'name' => 'Paper', 'unit' => 'ream']);

        $disposal = new Disposal([
            'quantity' => 5,
            'disposal_date' => now(),
            'place_of_storage' => 'Warehouse A',
            'disposal_mode' => 'sold_public',
            'wmr_inspection_item_no' => 1,
            'official_receipt_no' => 'OR-100',
            'sale_date' => now(),
            'sale_amount' => 500,
            'custodian_printed_name' => 'Prepared Person',
            'approved_by_printed_name' => 'Approver Person',
            'inspection_officer_printed_name' => 'Inspector Person',
            'witness_printed_name' => 'Witness Person',
        ]);
        $disposal->setRelation('office', $office);
        $disposal->setRelation('item', $item);

        $values = app(OwwaTemplateExportService::class)->cellValuesForDisposal(
            $disposal,
            'Consumable/Disposal/Appendix 65 - WMR.xlsx'
        );

        $signatures = OwwaCellMapping::form('WMR')['signatures'];

        $this->assertSame('Prepared Person', $values[$signatures['prepared_by']]);
        $this->assertSame('Approver Person', $values[$signatures['approved_by']]);
        $this->assertSame('Inspector Person', $values[$signatures['inspected_by']]);
        $this->assertSame('Witness Person', $values[$signatures['witness']]);
        $this->assertSame('1', $values['C34']);
        $this->assertSame(500.0, $values['I23']);

        $sheet = app(OwwaTemplateExportService::class)
            ->renderFilledSpreadsheet('Consumable/Disposal/Appendix 65 - WMR.xlsx', $values)
            ->getActiveSheet();

        $this->assertSame('1', (string) $sheet->getCell('C34')->getValue());
        $this->assertSame(500.0, (float) $sheet->getCell('I23')->getValue());
    }

    public function test_iirup_signatory_cells_use_configured_map(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item(['item_code' => 'PPE-002', 'name' => 'Desktop PC']);

        $disposal = new Disposal([
            'quantity' => 2,
            'disposal_date' => now(),
            'reason' => 'Beyond repair',
            'acquisition_cost' => 1000,
            'accumulated_depreciation' => 500,
            'accumulated_impairment_losses' => 100,
            'appraised_value' => 800,
            'iirup_disposal_mode' => 'sale',
            'iirup_disposal_amount' => 750,
            'official_receipt_no' => 'OR-200',
            'sale_amount' => 750,
            'custodian_printed_name' => 'Custodian A',
            'accountable_officer_designation' => 'Property Officer',
            'approved_by_printed_name' => 'Approver A',
            'authorized_official_designation' => 'Regional Director',
            'inspection_officer_printed_name' => 'Inspector A',
            'witness_printed_name' => 'Witness A',
        ]);
        $disposal->setRelation('office', $office);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', null);

        $values = app(OwwaTemplateExportService::class)->cellValuesForDisposal(
            $disposal,
            'ppe/Disposal/Appendix 74 - IIRUP.xlsx'
        );

        $signatures = OwwaCellMapping::form('IIRUP')['signatures'];

        $this->assertSame('Custodian A', $values[$signatures['custodian']]);
        $this->assertSame('Approver A', $values[$signatures['approved_by']]);
        $this->assertSame('Inspector A', $values[$signatures['inspection_officer']]);
        $this->assertSame('Witness A', $values[$signatures['witness']]);
        $this->assertSame('Property Officer', $values[$signatures['accountable_designation']]);
        $this->assertSame('Regional Director', $values[$signatures['authorized_designation']]);
        $this->assertSame(1000.0, $values['F15']);
        $this->assertSame(2000.0, $values['G15']);
        $this->assertSame(500.0, $values['H15']);
        $this->assertSame(100.0, $values['I15']);
        $this->assertSame(1400.0, $values['J15']);
        $this->assertSame(750.0, $values['L15']);
        $this->assertSame(750.0, $values['P15']);
        $this->assertSame(800.0, $values['Q15']);
        $this->assertSame('OR-200', $values['R15']);
        $this->assertSame(750.0, $values['S15']);

        $sheet = app(OwwaTemplateExportService::class)
            ->renderFilledSpreadsheet('ppe/Disposal/Appendix 74 - IIRUP.xlsx', $values)
            ->getActiveSheet();

        $this->assertSame(1400.0, (float) $sheet->getCell('J15')->getValue());
        $this->assertSame('Regional Director', (string) $sheet->getCell('H42')->getValue());
    }

    public function test_rlsddp_signatory_cells_use_configured_map(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item(['item_code' => 'PPE-003', 'name' => 'Printer']);

        $disposal = new Disposal([
            'quantity' => 1,
            'disposal_date' => now(),
            'custodian_printed_name' => 'Accountable Officer',
            'immediate_supervisor_printed_name' => 'Supervisor Name',
        ]);
        $disposal->setRelation('office', $office);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', null);
        $disposal->setRelation('inventoryUnit', null);

        $values = app(OwwaTemplateExportService::class)->cellValuesForDisposal(
            $disposal,
            'Appendix 75 - RLSDDP.xls'
        );

        $signatures = OwwaCellMapping::form('RLSDDP')['signatures'];

        $this->assertSame('Accountable Officer', $values[$signatures['accountable_officer']]);
        $this->assertSame('Supervisor Name', $values[$signatures['noted_by']]);
    }

    public function test_rlsddp_export_prefers_inventory_unit_property_and_cost(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item(['item_code' => 'SE-001', 'name' => 'Wall Clock']);
        $acquisition = new Acquisition(['unit_cost' => 3200]);
        $unit = new InventoryUnit([
            'property_number' => 'SPLV-2026-ICT-106-01-003',
            'status' => InventoryUnit::STATUS_ISSUED,
        ]);
        $unit->setRelation('acquisition', $acquisition);

        $disposal = new Disposal([
            'quantity' => 1,
            'disposal_date' => now(),
            'property_number' => 'manual-entry',
            'acquisition_cost' => null,
        ]);
        $disposal->setRelation('office', $office);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', null);
        $disposal->setRelation('inventoryUnit', $unit);

        $values = DisposalExportLayout::cellValuesForRlsddp($disposal);
        $detailStart = OwwaCellMapping::detailRowBase('RLSDDP');
        $cols = OwwaCellMapping::detailColumns('RLSDDP');

        $this->assertSame(
            'SPLV-2026-ICT-106-01-003',
            $values[OwwaCellMapping::columnCell($cols['property_no'], $detailStart)],
        );
        $this->assertSame(
            3200.0,
            $values[OwwaCellMapping::columnCell($cols['acquisition_cost'], $detailStart)],
        );
    }

    public function test_consumable_transfer_maps_rsmi_detail_line(): void
    {
        $fromOffice = new Office(['name' => 'From Office', 'fund_cluster' => '01', 'code' => 'FO']);
        $toOffice = new Office(['name' => 'To Office']);
        $item = new Item(['item_code' => 'CON-010', 'name' => 'Bond Paper', 'unit' => 'ream']);

        $transfer = new Transfer([
            'reference_code' => '2026-01-0200',
            'quantity' => 3,
            'transfer_date' => now(),
            'released_by_printed_name' => 'Supply Custodian',
        ]);
        $transfer->setRelation('item', $item);
        $transfer->setRelation('fromOffice', $fromOffice);
        $transfer->setRelation('toOffice', $toOffice);
        $transfer->setRelation('recordedBy', null);

        $values = app(OwwaTemplateExportService::class)->cellValuesForTransfer(
            $transfer,
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $detailStart = OwwaCellMapping::detailRowBase('RSMI');
        $cols = OwwaCellMapping::detailColumns('RSMI');

        $this->assertSame('2026-01-0200', $values[OwwaCellMapping::columnCell($cols['ris_no'], $detailStart)]);
        $this->assertSame('CON-010', $values[OwwaCellMapping::columnCell($cols['stock_no'], $detailStart)]);
        $this->assertSame('3', $values[OwwaCellMapping::columnCell($cols['quantity'], $detailStart)]);
        $this->assertStringContainsString('Transfer to To Office', (string) $values[OwwaCellMapping::columnCell($cols['item'], $detailStart)]);
        $this->assertArrayNotHasKey('A52', $values);
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

        $method = new ReflectionMethod(OwwaItemReportService::class, 'cellValuesForPhysicalCount');
        $values = $method->invoke(app(OwwaItemReportService::class), $session);

        $signatures = OwwaCellMapping::form('RPCI')['signatures'];

        $this->assertSame('Certifier', $values[$signatures['certified_by']]);
        $this->assertSame('Approver', $values[$signatures['approved_by']]);
        $this->assertSame('Verifier', $values[$signatures['verified_by']]);
    }
}
