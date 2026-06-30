<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Transfer;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OwwaTemplateExportMappingTest extends TestCase
{
    public function test_ris_header_uses_template_row_and_column_layout(): void
    {
        $office = new Office([
            'name' => 'OWWA Regional Office IV-A',
            'fund_cluster' => '01',
            'code' => 'RO4A',
        ]);
        $department = new Department([
            'name' => 'Operations',
            'code' => 'OPS',
        ]);
        $category = new ItemCategory(['name' => 'Consumables']);
        $item = new Item([
            'item_code' => 'CON-002',
            'name' => 'Ballpoint Pen (Blue)',
            'unit' => 'piece',
        ]);
        $item->setRelation('category', $category);

        $requester = new User(['name' => 'Jane Requester']);
        $approver = new User(['name' => 'John Approver']);

        $line = new RequisitionItem([
            'quantity' => 2,
            'stock_available' => 10,
            'quantity_issued' => 2,
            'issue_remarks' => 'Issued in full',
        ]);
        $line->setRelation('item', $item);

        $requisition = new Requisition([
            'reference_code' => '2026-01-0013',
            'purpose' => 'Office supplies replenishment',
            'created_at' => now(),
        ]);
        $requisition->setRelation('office', $office);
        $requisition->setRelation('department', $department);
        $requisition->setRelation('requestedBy', $requester);
        $requisition->setRelation('approvedBy', $approver);
        $requisition->setRelation('items', Collection::make([$line]));

        $values = app(OwwaTemplateExportService::class)->cellValuesForRequisition($requisition);

        $this->assertArrayHasKey('F9', $values);
        $this->assertStringContainsString('2026-01-0013', (string) $values['F9']);
        $this->assertStringNotContainsString('RIS No.', (string) ($values['G6'] ?? ''));
        $this->assertArrayNotHasKey('G7', $values);

        $this->assertStringContainsString('OWWA Regional Office IV-A', (string) $values['A6']);
        $this->assertStringContainsString('01', (string) $values['G6']);
        $this->assertStringContainsString('Operations', (string) $values['A8']);
        $this->assertStringContainsString('OPS', (string) $values['F8']);
        $this->assertStringContainsString('OWWA Regional Office IV-A', (string) $values['A9']);

        $this->assertSame('X', $values['E12']);
        $this->assertSame('2', $values['G12']);
        $this->assertSame('Issued in full', $values['H12']);
        $this->assertSame('Jane Requester', $values['B37']);
        $this->assertSame('John Approver', $values['D37']);
    }

    public function test_ptr_detail_row_starts_at_row_seventeen(): void
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
        ]);
        $transfer->setRelation('item', $item);
        $transfer->setRelation('fromOffice', $fromOffice);
        $transfer->setRelation('toOffice', $toOffice);
        $transfer->setRelation('recordedBy', null);

        $values = app(OwwaTemplateExportService::class)->cellValuesForTransfer(
            $transfer,
            'ppe/Transfer/Appendix 76 - PTR.xls'
        );

        $this->assertArrayHasKey('A17', $values);
        $this->assertArrayNotHasKey('A18', $values);
        $this->assertSame('PPE-001', $values['B17']);
    }

    public function test_cell_map_config_defines_ris_and_ptr_forms(): void
    {
        $this->assertSame('F9', OwwaCellMapping::form('RIS')['header']['ris_no']['cell']);
        $this->assertSame(12, OwwaCellMapping::detailRowBase('RIS'));
        $this->assertSame(17, OwwaCellMapping::detailRowBase('PTR'));
    }

    public function test_rsmi_linked_issuance_uses_separate_serial_and_ris_numbers(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01', 'code' => 'OPS']);
        $department = new Department(['name' => 'Operations', 'code' => 'OPS']);
        $item = new Item(['item_code' => 'CON-001', 'name' => 'Paper', 'unit' => 'ream']);
        $requisition = new Requisition(['reference_code' => '2026-01-0005']);

        $issuance = new Issuance([
            'reference_code' => '2026-01-0012',
            'quantity' => 2,
            'issuance_date' => now(),
            'requisition_id' => 1,
        ]);
        $issuance->setRelation('requisition', $requisition);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', $department);
        $issuance->setRelation('item', $item);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance,
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $this->assertStringContainsString('2026-01-0012', (string) $values['G6']);
        $this->assertSame('2026-01-0005', $values['A12']);
    }

    public function test_rsmi_unlinked_issuance_leaves_ris_column_blank(): void
    {
        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $item = new Item(['item_code' => 'CON-001', 'name' => 'Paper', 'unit' => 'ream']);

        $issuance = new Issuance([
            'reference_code' => '2026-01-0012',
            'quantity' => 2,
            'issuance_date' => now(),
        ]);
        $issuance->setRelation('requisition', null);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('item', $item);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance,
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $this->assertStringContainsString('2026-01-0012', (string) $values['G6']);
        $this->assertSame('', $values['A12']);
    }

    public function test_demo_seeder_does_not_assign_ris_prefix_to_issuances(): void
    {
        $source = file_get_contents(base_path('database/seeders/DemoDataSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("'RIS-2026-'", $source);
        $this->assertStringContainsString("'2026-01-'", $source);
    }
}
