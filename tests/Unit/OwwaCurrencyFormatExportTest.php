<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\IssuanceBatch;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportStandards;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Font;
use Tests\TestCase;

class OwwaCurrencyFormatExportTest extends TestCase
{
    public function test_pr_export_applies_peso_format_to_unit_cost_and_amount_columns(): void
    {
        $template = 'Consumable/Acquisitions/Appendix 60 - PR.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('PR template is not installed.');
        }

        $values = [
            'A11' => 'CON-100',
            'C11' => 'Office supplies',
            'D11' => '5',
            'E11' => 25.50,
            'F11' => 127.50,
        ];

        $sheet = app(OwwaTemplateExportService::class)
            ->renderFilledSpreadsheet($template, $values)
            ->getActiveSheet();

        $formatCode = OwwaExportStandards::currencyExcelFormatCode();

        $this->assertStringContainsString('P', $sheet->getStyle('E11')->getNumberFormat()->getFormatCode());
        $this->assertSame($formatCode, $sheet->getStyle('F11')->getNumberFormat()->getFormatCode());
        $this->assertSame(25.50, (float) $sheet->getCell('E11')->getValue());
        $this->assertSame(127.50, (float) $sheet->getCell('F11')->getValue());
        $this->assertStringContainsString('P', (string) $sheet->getCell('E11')->getFormattedValue());
    }

    public function test_po_export_applies_peso_format_to_unit_cost_and_amount_columns(): void
    {
        $template = 'Consumable/Acquisitions/Appendix 61 - PO.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('PO template is not installed.');
        }

        $startRow = OwwaCellMapping::detailRowBase('PO');
        $values = [
            'A'.$startRow => 'CON-100',
            'C'.$startRow => 'Office supplies',
            'D'.$startRow => '10',
            'E'.$startRow => 185.0,
            'F'.$startRow => 1850.0,
        ];

        $sheet = app(OwwaTemplateExportService::class)
            ->renderFilledSpreadsheet($template, $values)
            ->getActiveSheet();

        $this->assertSame(
            OwwaExportStandards::currencyExcelFormatCode(),
            $sheet->getStyle('E'.$startRow)->getNumberFormat()->getFormatCode(),
        );
        $this->assertSame(
            OwwaExportStandards::currencyExcelFormatCode(),
            $sheet->getStyle('F'.$startRow)->getNumberFormat()->getFormatCode(),
        );
        $this->assertStringContainsString('P', (string) $sheet->getCell('F'.$startRow)->getFormattedValue());
    }

    public function test_rsmi_export_applies_peso_format_to_detail_and_recap_monetary_columns(): void
    {
        $template = 'Consumable/Issuances/Appendix 64 - RSMI.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('RSMI template is not installed.');
        }

        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01', 'code' => 'OPS']);
        $item = new Item(['item_code' => 'CON-001', 'name' => 'Paper', 'unit' => 'ream']);
        $requisition = new Requisition(['reference_code' => '2026-01-0005']);

        $issuance = new Issuance([
            'reference_code' => '2026-01-0012',
            'quantity' => 2,
            'unit_cost' => 15.5,
            'amount' => 31.0,
            'issuance_date' => now(),
        ]);
        $issuance->setRelation('requisition', $requisition);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('item', $item);

        $service = app(OwwaTemplateExportService::class);
        $cellValues = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $cellValues)->getActiveSheet();

        $detailStart = OwwaCellMapping::detailRowBase('RSMI');
        $recapStart = (int) OwwaCellMapping::form('RSMI')['detail']['recap_start_row'];
        $formatCode = OwwaExportStandards::currencyExcelFormatCode();

        $this->assertSame($formatCode, $sheet->getStyle('G'.$detailStart)->getNumberFormat()->getFormatCode());
        $this->assertSame($formatCode, $sheet->getStyle('H'.$detailStart)->getNumberFormat()->getFormatCode());
        $this->assertSame($formatCode, $sheet->getStyle('F'.$recapStart)->getNumberFormat()->getFormatCode());
        $this->assertSame($formatCode, $sheet->getStyle('G'.$recapStart)->getNumberFormat()->getFormatCode());
        $this->assertSame(15.5, (float) $sheet->getCell('G'.$detailStart)->getValue());
        $this->assertStringContainsString('P', (string) $sheet->getCell('H'.$detailStart)->getFormattedValue());
    }

    public function test_ics_export_widens_monetary_columns_to_avoid_hash_overflow(): void
    {
        $template = 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('ICS template is not installed.');
        }

        $office = new Office(['name' => 'Regional Office', 'fund_cluster' => '01', 'code' => 'OPS']);
        $item = new Item([
            'item_code' => 'SEMI-001',
            'name' => 'Router',
            'unit' => 'piece',
            'estimated_useful_life' => '5 yrs',
        ]);

        $issuance = new Issuance([
            'reference_code' => '2026-07-0007',
            'quantity' => 1,
            'unit_cost' => 4500,
            'amount' => 4500,
            'property_number' => 'SPLV-2026-IT-106-006-OWWA-IVA',
            'issuance_date' => now(),
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('issuedBy', null);
        $issuance->setRelation('issuedTo', null);
        $issuance->setRelation('batch', null);

        $service = app(OwwaTemplateExportService::class);
        $cellValues = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $cellValues)->getActiveSheet();

        $detailStart = OwwaCellMapping::detailRowBase('ICS');
        $formatCode = OwwaExportStandards::currencyExcelFormatCode();

        $this->assertSame($formatCode, $sheet->getStyle('C'.$detailStart)->getNumberFormat()->getFormatCode());
        $this->assertSame(4500.0, (float) $sheet->getCell('C'.$detailStart)->getValue());
        $this->assertGreaterThanOrEqual(12.0, (float) $sheet->getColumnDimension('C')->getWidth());
        $this->assertGreaterThanOrEqual(12.0, (float) $sheet->getColumnDimension('D')->getWidth());
        $this->assertStringNotContainsString('###', (string) $sheet->getCell('C'.$detailStart)->getFormattedValue());
    }

    public function test_ics_export_preserves_signature_labels_and_writes_values_on_lines(): void
    {
        $template = 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('ICS template is not installed.');
        }

        $office = new Office([
            'name' => 'OWWA Regional Office IV-A',
            'supply_custodian_designation' => 'Supply Officer II',
        ]);
        $recipientOffice = new Office(['name' => 'Satellite Office']);
        $recipientDepartment = new Department(['name' => 'Programs Unit']);
        $issuedBy = new User(['name' => 'Maria Custodian']);
        $issuedBy->setRelation('office', $office);
        $issuedTo = new User(['name' => 'Juan Recipient']);
        $issuedTo->setRelation('office', $recipientOffice);
        $issuedTo->setRelation('department', $recipientDepartment);
        $item = new Item([
            'item_code' => 'SEMI-001',
            'name' => 'Router',
            'unit' => 'piece',
        ]);
        $batch = new IssuanceBatch([
            'reference_code' => '2026-07-0007',
            'custodian_printed_name' => 'Maria Custodian',
            'custodian_designation' => 'Supply Officer II',
            'issued_to_designation' => 'Programs Unit',
        ]);

        $issuance = new Issuance([
            'reference_code' => '2026-07-0007',
            'quantity' => 1,
            'unit_cost' => 4500,
            'amount' => 4500,
            'issuance_date' => '2026-07-17',
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', $recipientDepartment);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('issuedBy', $issuedBy);
        $issuance->setRelation('issuedTo', $issuedTo);
        $issuance->setRelation('batch', $batch);

        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $values)->getActiveSheet();

        $this->assertArrayNotHasKey('A44', $values);
        $this->assertArrayNotHasKey('F44', $values);
        $this->assertArrayNotHasKey('A49', $values);
        $this->assertArrayNotHasKey('F49', $values);
        $this->assertArrayNotHasKey('A51', $values);
        $this->assertArrayNotHasKey('F51', $values);
        $this->assertSame('Maria Custodian', $values['A46']);
        $this->assertSame('Juan Recipient', $values['F46']);
        $this->assertSame('Supply Officer II', $values['A48']);
        $this->assertSame('Programs Unit', $values['F48']);
        $this->assertSame('2026-07-17', $values['A50']);
        $this->assertSame('2026-07-17', $values['F50']);
        $this->assertSame('Received  from:', $sheet->getCell('A44')->getValue());
        $this->assertSame('Received by:', $sheet->getCell('F44')->getValue());
        $this->assertSame('Position/Office', $sheet->getCell('A49')->getValue());
        $this->assertSame('Position/Office', $sheet->getCell('F49')->getValue());
        $this->assertSame('Date', $sheet->getCell('A51')->getValue());
        $this->assertSame('Date', $sheet->getCell('F51')->getValue());
        $this->assertSame('Maria Custodian', $sheet->getCell('A46')->getValue());
        $this->assertSame('Juan Recipient', $sheet->getCell('F46')->getValue());
        $this->assertSame('Supply Officer II', $sheet->getCell('A48')->getValue());
        $this->assertSame('Programs Unit', $sheet->getCell('F48')->getValue());
        $this->assertSame('2026-07-17', $sheet->getCell('A50')->getValue());
        $this->assertSame('2026-07-17', $sheet->getCell('F50')->getValue());
        $this->assertSame('none', $sheet->getStyle('A46')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('none', $sheet->getStyle('F46')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A46')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('F46')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A48')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A50')->getFont()->getUnderline());
    }

    public function test_ics_export_keeps_fund_cluster_label_blank_without_clearing_template_text(): void
    {
        $template = 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('ICS template is not installed.');
        }

        $office = new Office(['name' => 'OWWA Regional Office IV-A']);
        $item = new Item([
            'item_code' => 'SEMI-001',
            'name' => 'Router',
            'unit' => 'piece',
        ]);
        $issuance = new Issuance([
            'reference_code' => '2026-07-0011',
            'quantity' => 1,
            'unit_cost' => 4500,
            'amount' => 4500,
            'issuance_date' => '2026-07-17',
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('issuedBy', null);
        $issuance->setRelation('issuedTo', null);
        $issuance->setRelation('batch', null);

        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $values)->getActiveSheet();

        $this->assertArrayNotHasKey('A7', $values);
        $this->assertStringContainsString('Fund Cluster', (string) $sheet->getCell('A7')->getValue());
    }

    public function test_ics_export_keeps_template_underscore_lines_when_signatories_are_blank(): void
    {
        $template = 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('ICS template is not installed.');
        }

        $office = new Office(['name' => null, 'supply_custodian_designation' => null]);
        $item = new Item([
            'item_code' => 'SEMI-002',
            'name' => 'Printer',
            'unit' => 'piece',
        ]);
        $issuance = new Issuance([
            'reference_code' => '2026-07-0009',
            'quantity' => 1,
            'unit_cost' => 1200,
            'amount' => 1200,
            'issuance_date' => null,
            'custodian_printed_name' => null,
            'custodian_designation' => null,
            'issued_to_designation' => null,
            'received_from_name' => null,
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('issuedBy', null);
        $issuance->setRelation('issuedTo', null);
        $issuance->setRelation('batch', null);

        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $values)->getActiveSheet();

        $this->assertArrayNotHasKey('A46', $values);
        $this->assertArrayNotHasKey('F46', $values);
        $this->assertArrayNotHasKey('A48', $values);
        $this->assertArrayNotHasKey('F48', $values);
        $this->assertSame(str_repeat('_', 34), $sheet->getCell('A46')->getValue());
        $this->assertSame(str_repeat('_', 30), $sheet->getCell('F46')->getValue());
        $this->assertSame(str_repeat('_', 34), $sheet->getCell('A48')->getValue());
        $this->assertSame(str_repeat('_', 30), $sheet->getCell('F48')->getValue());
        $this->assertSame(str_repeat('_', 34), $sheet->getCell('A50')->getValue());
        $this->assertSame(str_repeat('_', 30), $sheet->getCell('F50')->getValue());
        $this->assertSame('Position/Office', $sheet->getCell('A49')->getValue());
        $this->assertSame('Date', $sheet->getCell('A51')->getValue());
    }

    public function test_par_export_preserves_signature_labels_and_writes_values_on_underscore_lines(): void
    {
        $template = 'ppe/Issuances/Appendix 71 - PAR.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('PAR template is not installed.');
        }

        $office = new Office([
            'name' => 'OWWA Regional Office IV-A',
            'supply_custodian_designation' => 'Supply Officer II',
        ]);
        $recipientOffice = new Office(['name' => 'Satellite Office']);
        $recipientDepartment = new Department(['name' => 'Programs Unit']);
        $issuedBy = new User(['name' => 'Maria Custodian']);
        $issuedBy->setRelation('office', $office);
        $issuedTo = new User(['name' => 'Juan Recipient']);
        $issuedTo->setRelation('office', $recipientOffice);
        $issuedTo->setRelation('department', $recipientDepartment);
        $item = new Item([
            'item_code' => 'PPE-001',
            'name' => 'Laptop',
            'unit' => 'unit',
        ]);
        $batch = new IssuanceBatch([
            'reference_code' => '2026-07-0010',
            'custodian_printed_name' => 'Maria Custodian',
            'custodian_designation' => 'Supply Officer II',
            'issued_to_designation' => 'Programs Unit',
        ]);

        $issuance = new Issuance([
            'reference_code' => '2026-07-0010',
            'quantity' => 1,
            'unit_cost' => 45000,
            'amount' => 45000,
            'issuance_date' => '2026-07-17',
        ]);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', $recipientDepartment);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('issuedBy', $issuedBy);
        $issuance->setRelation('issuedTo', $issuedTo);
        $issuance->setRelation('batch', $batch);

        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForIssuance($issuance, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $values)->getActiveSheet();

        $this->assertArrayNotHasKey('A44', $values);
        $this->assertArrayNotHasKey('D44', $values);
        $this->assertArrayNotHasKey('A48', $values);
        $this->assertArrayNotHasKey('D48', $values);
        $this->assertArrayNotHasKey('A50', $values);
        $this->assertArrayNotHasKey('D50', $values);
        $this->assertSame('Juan Recipient', $values['A45']);
        $this->assertSame('Maria Custodian', $values['D45']);
        $this->assertSame('Programs Unit', $values['A47']);
        $this->assertSame('Supply Officer II', $values['D47']);
        $this->assertSame('2026-07-17', $values['A49']);
        $this->assertSame('2026-07-17', $values['D49']);
        $this->assertSame('Received by: ', $sheet->getCell('A44')->getValue());
        $this->assertSame('Issued by: ', $sheet->getCell('D44')->getValue());
        $this->assertSame('Position/Office', $sheet->getCell('A48')->getValue());
        $this->assertSame('Date', $sheet->getCell('A50')->getValue());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A45')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('D45')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A47')->getFont()->getUnderline());
        $this->assertSame(Font::UNDERLINE_SINGLE, $sheet->getStyle('A49')->getFont()->getUnderline());
    }

    public function test_ics_export_expands_detail_rows_when_lines_exceed_template_block(): void
    {
        $template = 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls';

        if (! is_readable(storage_path('app/templates/'.$template))) {
            $this->markTestSkipped('ICS template is not installed.');
        }

        $office = new Office(['name' => 'OWWA Regional Office IV-A']);
        $item = new Item([
            'item_code' => 'SEMI-EXP',
            'name' => 'Chair',
            'unit' => 'piece',
        ]);
        $batch = new IssuanceBatch([
            'reference_code' => '2026-07-0099',
            'custodian_printed_name' => 'Maria Custodian',
        ]);
        $batch->id = 99;

        $lines = new Collection;
        for ($i = 1; $i <= 33; $i++) {
            $line = new Issuance([
                'reference_code' => '2026-07-0099',
                'issuance_batch_id' => 99,
                'quantity' => 1,
                'unit_cost' => 100 + $i,
                'amount' => 100 + $i,
                'issuance_date' => '2026-07-17',
                'property_number' => 'SEMI-'.$i,
            ]);
            $line->setRelation('office', $office);
            $line->setRelation('item', $item);
            $line->setRelation('issuedBy', null);
            $line->setRelation('issuedTo', null);
            $line->setRelation('batch', $batch);
            $lines->push($line);
        }

        $batch->setRelation('lines', $lines);
        $representative = $lines->first();
        $representative->setRelation('batch', $batch);

        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForIssuance($representative, $template);
        $sheet = $service->renderFilledSpreadsheet($template, $values)->getActiveSheet();

        $this->assertSame('1', (string) $sheet->getCell('A44')->getValue());
        $this->assertSame('Received  from:', $sheet->getCell('A47')->getValue());
        $this->assertSame('Maria Custodian', $sheet->getCell('A49')->getValue());
        $this->assertSame('Position/Office', $sheet->getCell('A52')->getValue());
        $this->assertSame('Date', $sheet->getCell('A54')->getValue());
    }
}
