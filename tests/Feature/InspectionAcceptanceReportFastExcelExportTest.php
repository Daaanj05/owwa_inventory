<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\InspectionAcceptanceReportLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InspectionAcceptanceReportFastExcelExportService;
use App\Services\InspectionAcceptanceReportFastPdfExportService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\TestCase;

class InspectionAcceptanceReportFastExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_excel_spreadsheet_matches_fast_pdf_page_data(): void
    {
        $iar = $this->createReadyIar(
            iarNumber: 'IAR-2026-0042',
            description: 'Bond paper A4',
        );

        $pages = app(InspectionAcceptanceReportFastPdfExportService::class)
            ->buildPages($iar->fresh(['lines.item', 'purchaseOrder.purchaseRequest']));

        $spreadsheet = app(InspectionAcceptanceReportFastExcelExportService::class)->makeSpreadsheet($pages);
        $sheet = $spreadsheet->getActiveSheet();

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, (string) $sheet->getCell('A5')->getValue());
        $this->assertStringContainsString('Supplier Co.', (string) $sheet->getCell('A6')->getValue());
        $this->assertStringContainsString('IAR-2026-0042', (string) $sheet->getCell('C6')->getValue());
        $this->assertTrue($sheet->getStyle('C6')->getFont()->getBold());
        $this->assertSame('INSPECTION AND ACCEPTANCE REPORT', (string) $sheet->getCell('B3')->getValue());
        $this->assertEqualsWithDelta(15.4, $sheet->getColumnDimension('A')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(44.8, $sheet->getColumnDimension('B')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(12.9, $sheet->getColumnDimension('C')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(18.8, $sheet->getColumnDimension('D')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(1.25, $sheet->getPageMargins()->getLeft(), 0.01);
        $this->assertEqualsWithDelta(1.0, $sheet->getPageMargins()->getRight(), 0.01);
        $this->assertSame('Bond paper A4', (string) $sheet->getCell('B11')->getValue());
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $sheet->getStyle('B11')->getAlignment()->getHorizontal(),
        );
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());

        $sectionRow = 11 + app(InspectionAcceptanceReportFastPdfExportService::class)->maxRowsPerPage();
        $this->assertSame('INSPECTION', (string) $sheet->getCell('A'.$sectionRow)->getValue());
        $this->assertSame('ACCEPTANCE', (string) $sheet->getCell('C'.$sectionRow)->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_continues_with_second_sheet_when_lines_exceed_max_rows(): void
    {
        $iar = $this->createReadyIar(iarNumber: 'IAR-2026-0099', description: 'Line 1 item');
        $service = app(InspectionAcceptanceReportFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $iar->purchaseOrder->purchaseRequest->item_category_id,
            ]);
            $prLine = $iar->purchaseOrder->purchaseRequest->lines()->create([
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'quantity' => $i,
                'unit_cost' => 10,
                'amount' => 10 * $i,
            ]);
            $poLine = PurchaseOrderLine::query()->create([
                'purchase_order_id' => $iar->purchase_order_id,
                'acquisition_paperwork_line_id' => $prLine->id,
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'pr_quantity' => $i,
                'po_quantity' => $i,
                'is_ordered' => true,
                'unit_cost' => 10,
                'amount' => 10 * $i,
                'sort_order' => $i,
            ]);
            InspectionAcceptanceReportLine::query()->create([
                'inspection_acceptance_report_id' => $iar->id,
                'purchase_order_line_id' => $poLine->id,
                'acquisition_paperwork_line_id' => $prLine->id,
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'pr_quantity' => $i,
                'iar_quantity' => $i,
                'po_quantity' => $i,
                'sort_order' => $i,
            ]);
        }

        $pages = $service->buildPages($iar->fresh(['lines.item', 'purchaseOrder.purchaseRequest']));
        $spreadsheet = app(InspectionAcceptanceReportFastExcelExportService::class)->makeSpreadsheet($pages);

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertStringContainsString('IAR-2026-0099', $spreadsheet->getSheet(0)->getTitle());
        $this->assertStringContainsString('Cont', $spreadsheet->getSheet(1)->getTitle());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $iar = $this->createReadyIar(iarNumber: 'IAR-2026-0101', description: 'Folder long');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $iar->purchaseOrder?->purchaseRequest?->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.inspection-acceptance-report.excel', $iar));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
    }

    public function test_bulk_iar_xlsx_and_pdf_use_lookalike_exports(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $iar = $this->createReadyIar(iarNumber: 'IAR-2026-0400', description: 'Bulk line');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $iar->purchaseOrder?->purchaseRequest?->office_id,
        ]);

        $pdf = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'iar',
            'format' => 'pdf',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'category' => $iar->purchaseOrder?->purchaseRequest?->item_category_id,
        ]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'iar',
            'format' => 'xlsx',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'category' => $iar->purchaseOrder?->purchaseRequest?->item_category_id,
        ]));
        $xlsx->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('content-type'),
        );
    }

    protected function createReadyIar(string $iarNumber, string $description): InspectionAcceptanceReport
    {
        $office = Office::factory()->create([
            'is_regional_supply' => true,
            'name' => 'OWWA Region IV-A',
            'code' => 'R4A',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => '2026-03-15',
            'pr_number' => '2026-01-0042',
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $description,
            'unit' => 'pc',
            'quantity' => 3,
            'unit_cost' => 25.50,
            'amount' => 76.50,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'number' => 'PO-2026-0042',
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => '2026-04-01',
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => '2026-03-20',
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'description' => $description,
            'po_quantity' => 3,
            'unit_cost' => 25.50,
            'amount' => 76.50,
        ]);

        $poService = app(PurchaseOrderWorkflowService::class);
        $poService->submit($po->fresh(['lines']));
        $poService->approve($po->fresh());

        $iar = app(InspectionAcceptanceReportWorkflowService::class)->createFromApprovedPo($po->fresh());
        $iar->update([
            'number' => $iarNumber,
            'invoice_number' => 'INV100',
            'invoice_date' => '2026-03-18',
            'date_inspected' => '2026-03-19',
            'date_received' => '2026-03-20',
            'inspection_officer_name' => 'Inspector One',
            'custodian_name' => 'Custodian Two',
            'iar_date' => '2026-03-20',
        ]);
        $iar->lines()->update([
            'description' => $description,
            'iar_quantity' => 3,
        ]);

        return $iar->fresh(['lines.item', 'purchaseOrder.purchaseRequest']);
    }
}
