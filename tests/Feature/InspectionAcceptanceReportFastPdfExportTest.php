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
use App\Services\InspectionAcceptanceReportFastPdfExportService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionAcceptanceReportFastPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_header_fields_logos_and_inspection_blocks(): void
    {
        $iar = $this->createReadyIar(
            categoryName: 'Consumables',
            iarNumber: 'IAR-2026-0042',
            description: 'Bond paper A4',
        );

        $service = app(InspectionAcceptanceReportFastPdfExportService::class);
        $pages = $service->buildPages($iar->fresh([
            'lines.item',
            'purchaseOrder.purchaseRequest.office',
            'purchaseOrder.purchaseRequest.requestingOffice',
            'purchaseOrder.purchaseRequest.department',
        ]));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('IAR-2026-0042', $pages[0]['iar_no']);
        $this->assertSame('Bond paper A4', $pages[0]['lines'][0]['description']);
        $this->assertTrue($pages[0]['include_footer']);

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('IAR-2026-0042', $html);
        $this->assertStringContainsString('Bond paper A4', $html);
        $this->assertStringContainsString('Supplier Co.', $html);
        $this->assertStringContainsString('INV100', $html);
        $this->assertStringContainsString('INSPECTION AND ACCEPTANCE REPORT', $html);
        $this->assertStringContainsString('INSPECTION', $html);
        $this->assertStringContainsString('ACCEPTANCE', $html);
        $this->assertStringContainsString('Complete', $html);
        $this->assertStringContainsString('Partial', $html);
        $this->assertStringContainsString('Inspector One', $html);
        $this->assertStringContainsString('Custodian Two', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 8mm', $html);
        $this->assertStringContainsString('width: 16.8%', $html);
        $this->assertStringContainsString('width: 48.7%', $html);
        $this->assertStringContainsString('width: 14.0%', $html);
        $this->assertStringContainsString('width: 20.5%', $html);
        $this->assertStringContainsString('width: 65.5%', $html);
        $this->assertStringContainsString('width: 34.5%', $html);
        $this->assertStringContainsString('margin: 25.4mm 25.4mm 12.7mm 31.75mm', $html);
        $this->assertStringContainsString('form-lines', $html);
        $this->assertStringContainsString('line-cell cell-center">Bond paper A4', $html);
        $this->assertStringContainsString('meta-right">IAR No.', $html);
        $this->assertStringNotContainsString('Appendix 62', $html);
        $this->assertStringNotContainsString('>155<', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
    }

    public function test_fast_pdf_continues_on_second_page_when_lines_exceed_max_rows(): void
    {
        $iar = $this->createReadyIar(
            categoryName: 'Consumables',
            iarNumber: 'IAR-2026-0099',
            description: 'Line 1 item',
        );

        $service = app(InspectionAcceptanceReportFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();
        $categoryId = $iar->purchaseOrder->purchaseRequest->item_category_id;

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create(['item_category_id' => $categoryId]);
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

        $pages = $service->buildPages($iar->fresh([
            'lines.item',
            'purchaseOrder.purchaseRequest',
        ]));

        $this->assertCount(2, $pages);
        $this->assertSame('IAR-2026-0099', $pages[0]['iar_no']);
        $this->assertSame('IAR-2026-0099 (Cont. 2)', $pages[1]['iar_no']);
        $this->assertFalse($pages[0]['include_footer']);
        $this->assertTrue($pages[1]['include_footer']);
        $this->assertSame('', $pages[0]['inspection_officer_name']);
        $this->assertSame('Inspector One', $pages[1]['inspection_officer_name']);
    }

    public function test_fast_pdf_download_returns_pdf_bytes(): void
    {
        $iar = $this->createReadyIar(
            categoryName: 'PPE',
            iarNumber: 'IAR-2026-0100',
            description: 'Safety helmet',
        );

        $response = app(InspectionAcceptanceReportFastPdfExportService::class)
            ->download($iar->fresh(['lines.item', 'purchaseOrder.purchaseRequest']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_fast_pdf_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $iar = $this->createReadyIar(
            categoryName: 'Consumables',
            iarNumber: 'IAR-2026-0101',
            description: 'Folder long',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $iar->purchaseOrder?->purchaseRequest?->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.inspection-acceptance-report.pdf', $iar));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function categoryNameProvider(): array
    {
        return [
            'consumables' => ['Consumables'],
            'ppe' => ['PPE'],
            'semi_expendable' => ['Semi-Expendable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('categoryNameProvider')]
    public function test_fast_pdf_builds_for_each_category(string $categoryName): void
    {
        $iar = $this->createReadyIar(
            categoryName: $categoryName,
            iarNumber: 'IAR-2026-0200',
            description: "Item for {$categoryName}",
        );

        $pages = app(InspectionAcceptanceReportFastPdfExportService::class)
            ->buildPages($iar->fresh(['lines.item', 'purchaseOrder.purchaseRequest']));

        $this->assertCount(1, $pages);
        $this->assertSame("Item for {$categoryName}", $pages[0]['lines'][0]['description']);
    }

    public function test_export_visible_when_missing_fields_empty(): void
    {
        $iar = $this->createReadyIar(
            categoryName: 'Consumables',
            iarNumber: 'IAR-2026-0300',
            description: 'Ready line',
        );

        $this->assertSame([], $iar->fresh()->missingFields());
    }

    protected function createReadyIar(
        string $categoryName,
        string $iarNumber,
        string $description,
    ): InspectionAcceptanceReport {
        $office = Office::factory()->create([
            'is_regional_supply' => true,
            'name' => 'OWWA Region IV-A',
            'code' => 'R4A',
        ]);
        $category = ItemCategory::factory()->create(['name' => $categoryName]);
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
