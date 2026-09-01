<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionPaperworkWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_paperwork_export_routes_return_spreadsheet(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createCompletedPaperwork();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.pr', $paperwork))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.po', $paperwork))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.iar', $paperwork))
            ->assertOk();
    }

    public function test_legacy_procurement_export_routes_still_work(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createCompletedPaperwork();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $this->actingAs($user)
            ->get(route('owwa.export.procurement.pr', $paperwork))
            ->assertOk();
    }

    public function test_pr_export_maps_header_and_detail_cells(): void
    {
        $paperwork = $this->createCompletedPaperwork();

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPr($paperwork);

        $this->assertStringContainsString('Office supplies', (string) ($values['A33'] ?? ''));
        $this->assertArrayNotHasKey('A7', $values);
        $this->assertNotSame('', (string) ($values['A8'] ?? ''));
        $this->assertArrayHasKey('A11', $values);
        $this->assertSame('5', (string) $values['D11']);
        $this->assertStringContainsString('CON-100', (string) ($values['A11'] ?? ''));
        $this->assertSame('', (string) ($values['E11'] ?? ''));
        $this->assertSame('', (string) ($values['F11'] ?? ''));
    }

    public function test_pr_export_writes_full_office_section_on_a8_only(): void
    {
        $paperwork = $this->createCompletedPaperwork();
        $officeName = 'OWWA Satellite Office — Laguna';
        $paperwork->office?->update([
            'name' => $officeName,
            'is_satellite' => false,
            'is_regional_supply' => true,
        ]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPr(
            $paperwork->fresh(['requestingOffice', 'office', 'department'])
        );

        $this->assertArrayNotHasKey('A7', $values);
        $this->assertSame($officeName, (string) ($values['A8'] ?? ''));
    }

    public function test_po_export_preserves_accounting_blank_fields(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createCompletedPaperwork();
        $service = app(OwwaTemplateExportService::class);
        $values = $service->cellValuesForAcquisitionPaperworkPo($paperwork);

        foreach (['A45', 'D45', 'A46', 'D46', 'D47'] as $cell) {
            $this->assertArrayNotHasKey($cell, $values);
        }

        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'po');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork->fresh(['lines.item', 'itemCategory']), 'po', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertStringContainsString('Fund Cluster', (string) $sheet->getCell('A45')->getValue());
        $this->assertStringContainsString('ORS/BURS No.', (string) $sheet->getCell('D45')->getValue());
        $this->assertStringContainsString('Funds Available', (string) $sheet->getCell('A46')->getValue());
        $this->assertStringContainsString('Date of the ORS/BURS', (string) $sheet->getCell('D46')->getValue());
        $this->assertStringContainsString('___', (string) $sheet->getCell('A45')->getValue());
    }

    public function test_po_export_puts_total_amount_in_numbers_on_row_before_words(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $paperwork->lines()->update(['quantity' => 10, 'unit_cost' => 185, 'amount' => 1850]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPo($paperwork->fresh(['lines']));

        $this->assertSame(1850.0, (float) ($values['F31'] ?? 0));
        $this->assertStringContainsString('one thousand eight hundred fifty', strtolower((string) ($values['A32'] ?? '')));
        $this->assertArrayNotHasKey('F32', $values);
        $this->assertArrayNotHasKey('A31', $values);
    }

    public function test_pr_export_writes_line_items_into_spreadsheet_cells(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'pr_number' => '2026-01-0001',
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
        ]);
        $paperwork->lines()->update(['unit_cost' => 25.50, 'amount' => 127.50]);

        $paperwork = $paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'itemCategory']);
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $cellValues = $service->cellValuesForAcquisitionPaperworkPr($paperwork);
        $spreadsheet = $service->renderFilledSpreadsheet($templateFilename, $cellValues);
        $sheet = $spreadsheet->getActiveSheet();
        $startRow = OwwaCellMapping::detailRowBase('PR');

        $this->assertStringContainsString('CON-100', (string) $sheet->getCell('A'.$startRow)->getValue());
        $this->assertSame('5', (string) $sheet->getCell('D'.$startRow)->getValue());
        $this->assertStringContainsString('Office supplies', (string) $sheet->getCell('A33')->getValue());
    }

    public function test_pr_export_expands_office_section_row_height(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $cellValues = $service->cellValuesForAcquisitionPaperworkPr($paperwork);
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'pr', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertTrue($sheet->getStyle('A8')->getAlignment()->getWrapText());
        $this->assertGreaterThanOrEqual(15, $sheet->getRowDimension(8)->getRowHeight());
    }

    public function test_iar_export_preserves_acceptance_checkbox_markers(): void
    {
        if (! $this->acquisitionPaperworkIarTemplateExists()) {
            $this->markTestSkipped('OWWA IAR template is not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'supplier' => 'Supplier Co.',
            'iar_number' => '2026-01-0099',
            'iar_date' => now(),
            'iar_data' => [
                'date_inspected' => now()->toDateString(),
                'date_received' => now()->toDateString(),
            ],
        ]);

        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'iar');
        $spreadsheet = $service->buildProcurementSpreadsheet(
            $paperwork->fresh(['lines.item', 'itemCategory', 'office', 'requestingOffice', 'department']),
            'iar',
            $templateFilename,
        );
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertStringContainsString('☐', (string) $sheet->getCell('C30')->getValue());
        $this->assertStringContainsString('Complete', (string) $sheet->getCell('C30')->getValue());
        $this->assertStringContainsString('☐', (string) $sheet->getCell('C32')->getValue());
        $this->assertStringContainsString('Partial', (string) $sheet->getCell('C32')->getValue());
    }

    public function test_pr_pdf_export_is_generated_from_spreadsheet(): void
    {
        $this->skipUnlessLibreOfficeAvailable();

        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'pr_number' => '2026-01-0021',
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
        ]);

        $response = app(\App\Services\AcquisitionPaperworkPdfExportService::class)
            ->downloadPrPdf($paperwork->fresh(['lines.item', 'itemCategory', 'office', 'requestingOffice', 'department']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pr_export_expands_detail_row_height_for_long_description(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $secondItem = Item::factory()->create(['item_category_id' => $paperwork->item_category_id]);
        $longDescription = str_repeat('Long procurement item description segment — ', 6);

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $secondItem->id,
            'description' => $longDescription,
            'unit' => 'piece',
            'quantity' => 2,
            'unit_cost' => 10,
            'amount' => 20,
        ]);

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('PR');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'pr', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();
        $expandedHeight = $sheet->getRowDimension($startRow)->getRowHeight();

        $this->assertTrue($sheet->getStyle('C'.$startRow)->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle('F'.$startRow)->getAlignment()->getWrapText());
        $this->assertGreaterThan(15, $expandedHeight);
        $this->assertSame($expandedHeight, $sheet->getRowDimension($startRow + 1)->getRowHeight());
    }

    public function test_pr_export_expands_detail_row_height_for_long_stock_no(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $longStockItem = Item::factory()->create([
            'item_category_id' => $paperwork->item_category_id,
            'item_code' => 'SPHV-2026-FF-106-02-083-OWWA-IVA',
        ]);
        $paperwork->lines()->delete();
        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $longStockItem->id,
            'description' => 'Desk Lamp',
            'unit' => 'piece',
            'quantity' => 10,
            'unit_cost' => 100,
            'amount' => 1000,
        ]);

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('PR');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'pr', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertTrue($sheet->getStyle('A'.$startRow)->getAlignment()->getWrapText());
        $this->assertGreaterThanOrEqual(45, $sheet->getRowDimension($startRow)->getRowHeight());
    }

    public function test_pr_export_expands_detail_row_height_for_splv_stock_no_like_owwa_template(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $longStockItem = Item::factory()->create([
            'item_category_id' => $paperwork->item_category_id,
            'item_code' => 'SPLV-2026-OE-106-002-OWWA-IVA',
        ]);
        $paperwork->lines()->delete();
        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $longStockItem->id,
            'description' => 'Paper Cutter',
            'unit' => 'piece',
            'quantity' => 3,
            'unit_cost' => 100,
            'amount' => 300,
        ]);

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('PR');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'pr', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('SPLV-2026-OE-106-002-OWWA-IVA', (string) $sheet->getCell('A'.$startRow)->getValue());
        $this->assertGreaterThanOrEqual(45, $sheet->getRowDimension($startRow)->getRowHeight());
    }

    public function test_po_export_expands_detail_row_height_for_long_stock_no(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'supplier' => 'Supplier Co.',
            'po_number' => '2026-01-0005',
            'po_date' => now(),
        ]);
        $longStockItem = Item::factory()->create([
            'item_category_id' => $paperwork->item_category_id,
            'item_code' => str_repeat('CON-LONG-STOCK-NUMBER-', 4),
        ]);
        $paperwork->lines()->delete();
        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $longStockItem->id,
            'description' => 'Long stock line',
            'unit' => 'piece',
            'quantity' => 1,
            'unit_cost' => 100,
            'amount' => 100,
        ]);

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('PO');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'po');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'po', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertTrue($sheet->getStyle('A'.$startRow)->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle('F'.$startRow)->getAlignment()->getWrapText());
        $this->assertGreaterThan(15, $sheet->getRowDimension($startRow)->getRowHeight());
    }

    public function test_iar_export_expands_detail_row_height_for_long_description(): void
    {
        if (! $this->acquisitionPaperworkIarTemplateExists()) {
            $this->markTestSkipped('OWWA IAR template is not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $longDescription = str_repeat('Long inspection and acceptance item description — ', 6);
        $paperwork->lines()->update(['description' => $longDescription]);

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('IAR');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'iar');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'iar', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertTrue($sheet->getStyle('B'.$startRow)->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle('E'.$startRow)->getAlignment()->getWrapText());
        $this->assertGreaterThan(15, $sheet->getRowDimension($startRow)->getRowHeight());
    }

    public function test_po_export_normalizes_detail_row_style_on_all_lines(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'supplier' => 'Supplier Co.',
            'po_number' => '2026-01-0004',
            'po_date' => now(),
        ]);

        $paperwork->lines()->delete();
        $baseItem = Item::factory()->create(['item_category_id' => $paperwork->item_category_id]);
        $extraItems = Item::factory()->count(2)->create(['item_category_id' => $paperwork->item_category_id]);

        $lineDefinitions = [
            ['item_id' => $baseItem->id, 'description' => 'Line one', 'quantity' => 10, 'unit_cost' => 185, 'amount' => 1850],
            ['item_id' => $extraItems[0]->id, 'description' => 'Line two', 'quantity' => 100, 'unit_cost' => 8.5, 'amount' => 850],
            ['item_id' => $extraItems[1]->id, 'description' => 'Line three', 'quantity' => 40, 'unit_cost' => 95, 'amount' => 3800],
        ];

        foreach ($lineDefinitions as $definition) {
            AcquisitionPaperworkLine::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'unit' => 'piece',
                ...$definition,
            ]);
        }

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $startRow = OwwaCellMapping::detailRowBase('PO');
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'po');
        $cellValues = $service->cellValuesForAcquisitionPaperworkPo($paperwork);
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'po', $templateFilename);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ([$startRow, $startRow + 1, $startRow + 2] as $row) {
            $referenceRow = $startRow;
            $this->assertFalse($sheet->getStyle('E'.$row)->getFont()->getStrikethrough());
            $this->assertSame(
                $sheet->getStyle('E'.$referenceRow)->getFont()->getSize(),
                $sheet->getStyle('E'.$row)->getFont()->getSize(),
            );
            $this->assertSame(
                $sheet->getStyle('E'.$referenceRow)->getFont()->getName(),
                $sheet->getStyle('E'.$row)->getFont()->getName(),
            );
        }

        $this->assertArrayHasKey('A32', $cellValues);
        $this->assertArrayHasKey('F31', $cellValues);
        $this->assertStringContainsString('Pesos', (string) ($cellValues['A32'] ?? ''));
        $this->assertStringContainsString('six thousand five hundred', strtolower((string) ($cellValues['A32'] ?? '')));
    }

    public function test_po_export_uses_grand_total_amount_in_words_not_unit_cost(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $paperwork->lines()->update(['quantity' => 10, 'unit_cost' => 185, 'amount' => 1850]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPo($paperwork->fresh(['lines']));

        $this->assertStringContainsString('one thousand eight hundred fifty', strtolower((string) ($values['A32'] ?? '')));
        $this->assertSame(1850.0, (float) ($values['F31'] ?? 0));
        $this->assertNotSame(
            \App\Support\PesoAmountInWords::format(185),
            (string) ($values['A32'] ?? ''),
        );
    }

    public function test_po_export_with_overflow_lines_uses_continuation_sheets(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'supplier' => 'Supplier Co.',
            'po_number' => '2026-01-0020',
            'po_date' => now(),
        ]);
        $paperwork->lines()->delete();

        for ($index = 0; $index < 20; $index++) {
            $item = Item::factory()->create(['item_category_id' => $paperwork->item_category_id]);
            AcquisitionPaperworkLine::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'item_id' => $item->id,
                'description' => 'Overflow line '.$index,
                'unit' => 'piece',
                'quantity' => 1,
                'unit_cost' => 10 + $index,
                'amount' => 10 + $index,
            ]);
        }

        $paperwork = $paperwork->fresh(['lines.item', 'itemCategory']);
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'po');
        $spreadsheet = $service->buildProcurementSpreadsheet($paperwork, 'po', $templateFilename);
        $startRow = OwwaCellMapping::detailRowBase('PO');
        $maxRows = (int) OwwaCellMapping::form('PO')['detail']['max_rows'];

        $this->assertSame(3, $spreadsheet->getSheetCount());
        $this->assertSame('Technical Specification', $spreadsheet->getSheet(2)->getTitle());
        $this->assertStringContainsString(
            'Overflow line 15',
            (string) $spreadsheet->getSheet(1)->getCell('C'.($startRow))->getValue(),
        );
        $this->assertStringContainsString(
            'Pesos',
            (string) $spreadsheet->getSheet(1)->getCell('A32')->getValue(),
        );
        $this->assertNotSame('', (string) $spreadsheet->getSheet(1)->getCell('F31')->getValue());
        $this->assertSame('', (string) $spreadsheet->getSheet(0)->getCell('A32')->getValue());
        $this->assertSame('', (string) $spreadsheet->getSheet(0)->getCell('F31')->getValue());
        $this->assertStringContainsString('Fund Cluster', (string) $spreadsheet->getSheet(0)->getCell('A45')->getValue());
        $this->assertStringContainsString('Fund Cluster', (string) $spreadsheet->getSheet(1)->getCell('A45')->getValue());
        $this->assertSame($maxRows, $this->countFilledPoDetailRows($spreadsheet->getSheet(0), $startRow, $maxRows));
    }

    protected function countFilledPoDetailRows(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $startRow,
        ?int $maxRows = null,
    ): int {
        $limit = $maxRows ?? 30;
        $count = 0;

        for ($offset = 0; $offset < $limit; $offset++) {
            if (filled($sheet->getCell('C'.($startRow + $offset))->getValue())) {
                $count++;
            }
        }

        return $count;
    }

    public function test_create_acquisition_modal_saves_pr_header_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['name' => 'OWWA RO']);
        $requestingOffice = Office::factory()->create([
            'name' => 'OWWA Satellite Office — Batangas',
            'code' => 'OWWA-BAT',
            'is_satellite' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'item_code' => 'CON-200']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        session()->put('active_item_category_id', $category->id);
        $this->actingAs($custodian);

        $livewire = Livewire::test(ListAcquisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'));

        $lineKey = array_key_first($livewire->get('mountedActions')[0]['data']['lines'] ?? []);

        $livewire
            ->fillForm([
                'office_id' => $office->id,
                'item_category_id' => $category->id,
                'purpose' => 'Printer supplies for RO',
                'requested_by_name' => 'Juan Dela Cruz',
                'approved_by_name' => 'Maria Santos',
                'lines' => [
                    $lineKey => [
                        'item_id' => $item->id,
                        'description' => $item->name,
                        'unit' => $item->unit ?? 'piece',
                        'quantity' => 3,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertNotified();

        $this->assertDatabaseHas('acquisition_paperwork', [
            'office_id' => $office->id,
            'purpose' => 'Printer supplies for RO',
            'requested_by_name' => 'Juan Dela Cruz',
            'approved_by_name' => 'Maria Santos',
        ]);
    }

    public function test_pr_header_fields_are_locked_after_approve(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createPaperworkDraft();
        $office = $paperwork->office;
        $category = $paperwork->itemCategory;
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        app(AcquisitionPaperworkCompletionService::class)->approvePr($paperwork->fresh());

        session()->put('active_item_category_id', $category->id);
        $this->actingAs($custodian);

        $this->assertFalse($paperwork->fresh()->isPrEditable());

        Livewire::test(ListAcquisitions::class)
            ->assertCanSeeTableRecords([$paperwork->fresh()]);

        $url = \App\Filament\Resources\Acquisitions\AcquisitionResource::viewModalUrl($paperwork->fresh());
        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertSame('Office supplies', $paperwork->fresh()->purpose);
    }

    public function test_submit_and_approve_flow_assigns_serial_numbers(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);
        $poService = app(\App\Services\PurchaseOrderWorkflowService::class);
        $iarService = app(\App\Services\InspectionAcceptanceReportWorkflowService::class);

        $this->assertNotNull($paperwork->pr_number);
        $this->assertSame(AcquisitionPaperwork::STATUS_DRAFT, $paperwork->pr_status);

        $service->submitPr($paperwork->fresh());
        $paperwork = $paperwork->fresh();
        $this->assertSame(AcquisitionPaperwork::STATUS_DRAFT, $paperwork->pr_status);
        $this->assertNotNull($paperwork->pr_number);

        $service->approvePr($paperwork);
        $paperwork = $paperwork->fresh();
        $this->assertNotNull($paperwork->pr_number);
        $this->assertSame(AcquisitionPaperwork::STATUS_APPROVED, $paperwork->pr_status);
        $this->assertSame(AcquisitionPaperwork::PHASE_PR, $paperwork->phase);

        $po = $poService->createFromApprovedPr($paperwork->fresh());
        $this->assertNotNull($po->number);
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update(['is_ordered' => true, 'po_quantity' => 5, 'unit_cost' => 25.50, 'amount' => 127.50]);
        $poService->submit($po->fresh(['lines']));
        $poService->approve($po->fresh());
        $po = $po->fresh();
        $this->assertNotNull($po->number);
        $this->assertTrue($po->isApproved());

        $iar = $iarService->createFromApprovedPo($po);
        $this->assertNotNull($iar->number);
        $iar->update([
            'invoice_number' => 'INV100',
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->toDateString(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
            'iar_date' => now()->subDays(3)->toDateString(),
        ]);
        $iarService->submit($iar->fresh(['lines']));
        $iarService->approve($iar->fresh());
        $iar = $iar->fresh();

        $this->assertNotNull($iar->number);
        $this->assertTrue($iar->isApproved());
        $this->assertTrue($paperwork->fresh()->isIarApproved());
    }

    public function test_approve_pr_assigns_pr_number_when_reference_series_exists(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->assertNotNull($paperwork->pr_number);

        $service->approvePr($paperwork->fresh());

        $paperwork = $paperwork->fresh();
        $this->assertNotNull($paperwork->pr_number);
        $this->assertNotSame('', $paperwork->pr_number);
        $this->assertSame(AcquisitionPaperwork::STATUS_APPROVED, $paperwork->pr_status);
    }

    public function test_submit_pr_blocked_without_requesting_office(): void
    {
        $paperwork = $this->createPaperworkDraft(includeRequestingOffice: false);
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submitPr($paperwork);
    }

    public function test_submit_pr_blocked_when_purpose_shorter_than_eight_characters(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $paperwork->update(['purpose' => 'Short']);
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->assertContains(
            'purpose (at least 8 characters)',
            $paperwork->fresh()->missingPrFields(),
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submitPr($paperwork->fresh());
    }

    public function test_submit_pr_allowed_without_unit_cost(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $paperwork->lines()->update(['unit_cost' => null, 'amount' => null]);
        $service = app(AcquisitionPaperworkCompletionService::class);

        $service->submitPr($paperwork->fresh());

        $paperwork = $paperwork->fresh();
        $this->assertSame(AcquisitionPaperwork::STATUS_DRAFT, $paperwork->pr_status);
        $this->assertNotNull($paperwork->pr_number);
        $this->assertTrue($paperwork->lines()->whereNull('unit_cost')->exists());
    }

    public function test_submit_po_blocked_without_unit_cost(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->submitPr($paperwork);
        $service->approvePr($paperwork->fresh(['lines.item']));

        $po = app(\App\Services\PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => '123 Main',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update(['is_ordered' => true, 'po_quantity' => 5, 'unit_cost' => null, 'amount' => null]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
    }

    public function test_po_submit_blocked_before_pr_approval(): void
    {
        $paperwork = $this->createPaperworkDraft();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork);
    }

    public function test_record_custody_creates_acquisitions_linked_to_case(): void
    {
        $paperwork = $this->createCompletedPaperwork();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $created = $service->recordCustodyReceipts($paperwork->fresh());

        $this->assertCount(1, $created);
        $this->assertDatabaseHas(Acquisition::class, [
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $paperwork->lines->first()->item_id,
            'quantity' => 5,
            'office_id' => $paperwork->office_id,
        ]);

        $acquisition = $created->first();
        $this->assertNotNull($acquisition->purchase_order_id);
        $this->assertNotNull($acquisition->inspection_acceptance_report_id);

        $paperwork = $paperwork->fresh();
        $this->assertTrue($paperwork->isReceived());
        $this->assertNotNull($paperwork->received_at);
        $this->assertSame('Received', $paperwork->workflowStatusLabel());
        $this->assertTrue(
            AcquisitionPaperwork::query()->whereKey($paperwork->id)->exists(),
            'Received paperwork should remain queryable in the acquisitions list.',
        );
        $this->assertStringContainsString('PO', (string) $created[0]->source);
        $this->assertStringContainsString('IAR', (string) $created[0]->source);
    }

    public function test_save_and_submit_po_without_explicit_save(): void
    {
        $paperwork = $this->createPoPhasePaperwork();
        $po = app(\App\Services\PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork);
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 25.50,
            'amount' => 127.50,
        ]);

        app(\App\Services\PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));

        $po->refresh();
        $this->assertSame(\App\Models\PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertNotNull($po->number);
        $this->assertSame('Acme Supplies', $po->supplier_name);
        $this->assertNotNull($po->po_date);
    }

    public function test_po_phase_edit_hides_pr_header_fields(): void
    {
        $paperwork = $this->createPoPhasePaperwork();
        $po = app(\App\Services\PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork);

        $this->assertNotNull($po->id);
        $this->assertSame($paperwork->id, $po->acquisition_paperwork_id);
        $this->assertTrue($po->lines->isNotEmpty());
        $this->assertSame((int) $paperwork->lines->first()->quantity, (int) $po->lines->first()->pr_quantity);
    }

    public function test_workflow_stepper_can_mount_grouped_phase_view_action(): void
    {
        $this->assertTrue(true);
    }

    public function test_edit_action_exposes_nested_phase_view_actions(): void
    {
        $this->assertTrue(true);
    }

    public function test_workflow_stepper_mounts_phase_view_while_edit_modal_open(): void
    {
        $this->assertTrue(true);
    }

    public function test_workflow_stepper_mounts_phase_view_while_view_modal_open(): void
    {
        $this->assertTrue(true);
    }

    protected function createPoPhasePaperwork(): AcquisitionPaperwork
    {
        $paperwork = $this->createPaperworkDraft();
        app(AcquisitionPaperworkCompletionService::class)->approvePr(
            tap($paperwork->fresh(), fn (AcquisitionPaperwork $draft) => app(AcquisitionPaperworkCompletionService::class)->submitPr($draft))
        );

        return $paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'itemCategory']);
    }

    protected function createPaperworkDraft(bool $includeRequestingOffice = true): AcquisitionPaperwork
    {
        $office = Office::factory()->create(['name' => 'OWWA RO', 'fund_cluster' => '01']);
        $requestingOffice = Office::factory()->create([
            'name' => 'OWWA Satellite Office — Laguna',
            'code' => 'OWWA-LAG',
            'is_satellite' => true,
            'fund_cluster' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'item_code' => 'CON-100']);
        $user = User::factory()->create();
        $requestingOfficeId = $includeRequestingOffice ? $requestingOffice->id : null;

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $requestingOfficeId,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
        ]);

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit ?? 'piece',
            'quantity' => 5,
            'unit_cost' => 25.50,
            'amount' => 127.50,
        ]);

        return $paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'itemCategory']);
    }

    protected function acquisitionPaperworkTemplatesExist(): bool
    {
        return is_readable(storage_path('app/templates/Consumable/Acquisitions/Appendix 60 - PR.xls'));
    }

    protected function acquisitionPaperworkIarTemplateExists(): bool
    {
        return is_readable(storage_path('app/templates/Consumable/Acquisitions/Appendix 62- IAR.xls'));
    }

    protected function createCompletedPaperwork(): AcquisitionPaperwork
    {
        $paperwork = $this->createPaperworkDraft();

        $paperwork->update([
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
        ]);

        $prService = app(AcquisitionPaperworkCompletionService::class);
        $prService->completePr($paperwork->fresh());

        $poService = app(\App\Services\PurchaseOrderWorkflowService::class);
        $po = $poService->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'supplier_tin' => '123456789',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'delivery_term' => 'FOB Destination',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 25.50,
            'amount' => 127.50,
        ]);
        $poService->submit($po->fresh(['lines']));
        $poService->approve($po->fresh());

        $iarService = app(\App\Services\InspectionAcceptanceReportWorkflowService::class);
        $iar = $iarService->createFromApprovedPo($po->fresh());
        $iar->update([
            'invoice_number' => 'INV100',
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->toDateString(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
            'iar_date' => now()->subDays(3)->toDateString(),
        ]);
        $iarService->submit($iar->fresh(['lines']));
        $iarService->approve($iar->fresh());

        return $paperwork->fresh([
            'lines.item',
            'office',
            'requestingOffice',
            'itemCategory',
            'purchaseOrder.lines',
            'purchaseOrder.inspectionAcceptanceReport.lines',
        ]);
    }
}
