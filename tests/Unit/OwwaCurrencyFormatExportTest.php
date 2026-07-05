<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportStandards;
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
}
