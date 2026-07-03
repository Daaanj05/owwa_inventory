<?php

namespace Tests\Unit;

use App\Services\OwwaTemplateExportService;
use App\Support\OwwaSpreadsheetLayoutHelper;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Font;
use Tests\TestCase;

class OwwaAnnexA4HeaderFormattingTest extends TestCase
{
    public function test_apply_bold_label_plain_value_cell_formats_runs(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->getStyle('A7')->getFont()->setName('Times New Roman')->setSize(11);

        OwwaSpreadsheetLayoutHelper::applyBoldLabelPlainValueCell(
            $sheet,
            'A7',
            'Semi-Expendable Property: ',
            'INFORMATION & COMMUNICATION TECHNOLOGY',
        );

        $value = $sheet->getCell('A7')->getValue();
        $this->assertInstanceOf(RichText::class, $value);

        $runs = $value->getRichTextElements();
        $this->assertCount(2, $runs);
        $this->assertSame('Semi-Expendable Property: ', $runs[0]->getText());
        $this->assertTrue($runs[0]->getFont()->getBold());
        $this->assertSame('Times New Roman', $runs[0]->getFont()->getName());
        $this->assertEqualsWithDelta(11.0, $runs[0]->getFont()->getSize(), 0.01);
        $this->assertSame(Font::UNDERLINE_NONE, $runs[0]->getFont()->getUnderline());
        $this->assertSame('INFORMATION & COMMUNICATION TECHNOLOGY', $runs[1]->getText());
        $this->assertFalse($runs[1]->getFont()->getBold());
        $this->assertSame('Times New Roman', $runs[1]->getFont()->getName());
        $this->assertEqualsWithDelta(11.0, $runs[1]->getFont()->getSize(), 0.01);
        $this->assertSame(Font::UNDERLINE_NONE, $runs[1]->getFont()->getUnderline());
        $this->assertSame('Times New Roman', $sheet->getStyle('A7')->getFont()->getName());
        $this->assertSame(Font::UNDERLINE_NONE, $sheet->getStyle('A7')->getFont()->getUnderline());
    }

    public function test_build_annex_a4_spreadsheet_applies_property_type_header_formatting(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $template = storage_path('app/templates/Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx');
        if (! is_readable($template)) {
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
        $this->assertSame('Times New Roman', $runs[0]->getFont()->getName());
        $this->assertSame('Times New Roman', $runs[1]->getFont()->getName());
    }
}
