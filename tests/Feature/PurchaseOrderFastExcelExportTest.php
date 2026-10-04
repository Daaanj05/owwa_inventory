<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\PurchaseOrderFastExcelExportService;
use App\Services\PurchaseOrderFastPdfExportService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\TestCase;

class PurchaseOrderFastExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_excel_spreadsheet_matches_fast_pdf_page_data(): void
    {
        $po = $this->createReadyPo(
            poNumber: 'PO-2026-0042',
            description: 'Bond paper A4',
        );

        $pages = app(PurchaseOrderFastPdfExportService::class)
            ->buildPages($po->fresh(['orderedLines.item', 'purchaseRequest']));

        $spreadsheet = app(PurchaseOrderFastExcelExportService::class)->makeSpreadsheet($pages);
        $sheet = $spreadsheet->getActiveSheet();

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, (string) $sheet->getCell('A5')->getValue());
        $this->assertSame(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            $sheet->getStyle('A5')->getAlignment()->getHorizontal(),
        );
        $this->assertStringContainsString('Supplier Co.', (string) $sheet->getCell('A6')->getValue());
        $this->assertStringContainsString('PO-2026-0042', (string) $sheet->getCell('D6')->getValue());
        $this->assertTrue($sheet->getStyle('D6')->getFont()->getBold());
        $this->assertSame('PURCHASE ORDER', (string) $sheet->getCell('C3')->getValue());
        $this->assertSame(13.4, $sheet->getColumnDimension('A')->getWidth());
        $this->assertSame(35.0, $sheet->getColumnDimension('C')->getWidth());
        $this->assertSame('Bond paper A4', (string) $sheet->getCell('C14')->getValue());
        $this->assertEqualsWithDelta(25.5, (float) $sheet->getCell('E14')->getValue(), 0.001);
        $this->assertEqualsWithDelta(76.5, (float) $sheet->getCell('F14')->getValue(), 0.001);
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());

        $totalRow = 14 + app(PurchaseOrderFastPdfExportService::class)->maxRowsPerPage();
        $this->assertStringContainsString('(Total Amount in Words)', (string) $sheet->getCell('A'.$totalRow)->getValue());
        $this->assertEqualsWithDelta(76.5, (float) $sheet->getCell('F'.$totalRow)->getValue(), 0.001);

        $conformeNameRow = $totalRow + 3;
        $this->assertSame('Supplier Co.', (string) $sheet->getCell('A'.$conformeNameRow)->getValue());
        $acctRow = $totalRow + 7;
        $this->assertStringContainsString('Fund Cluster : ___', (string) $sheet->getCell('A'.$acctRow)->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_continues_with_second_sheet_when_lines_exceed_max_rows(): void
    {
        $po = $this->createReadyPo(poNumber: 'PO-2026-0099', description: 'Line 1 item');
        $service = app(PurchaseOrderFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create(['item_category_id' => $po->purchaseRequest->item_category_id]);
            $prLine = $po->purchaseRequest->lines()->create([
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'quantity' => $i,
                'unit_cost' => 10,
                'amount' => 10 * $i,
            ]);
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $po->id,
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
        }

        $pages = $service->buildPages($po->fresh(['orderedLines.item', 'purchaseRequest']));
        $spreadsheet = app(PurchaseOrderFastExcelExportService::class)->makeSpreadsheet($pages);

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertStringContainsString('PO-2026-0099', $spreadsheet->getSheet(0)->getTitle());
        $this->assertStringContainsString('Cont', $spreadsheet->getSheet(1)->getTitle());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $po = $this->createReadyPo(poNumber: 'PO-2026-0101', description: 'Folder long');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $po->purchaseRequest?->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.purchase-order.excel', $po));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
    }

    public function test_bulk_po_xlsx_and_pdf_use_lookalike_exports(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $po = $this->createReadyPo(poNumber: 'PO-2026-0400', description: 'Bulk line');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $po->purchaseRequest?->office_id,
        ]);

        $pdf = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'po',
            'format' => 'pdf',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'category' => $po->purchaseRequest?->item_category_id,
        ]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'po',
            'format' => 'xlsx',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'category' => $po->purchaseRequest?->item_category_id,
        ]));
        $xlsx->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('content-type'),
        );
    }

    protected function createReadyPo(string $poNumber, string $description): PurchaseOrder
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
            'number' => $poNumber,
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

        return $po->fresh(['orderedLines.item', 'purchaseRequest']);
    }
}
