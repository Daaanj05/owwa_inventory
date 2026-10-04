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
use App\Services\PurchaseOrderFastPdfExportService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderFastPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_header_fields_logos_costs_and_generated_on(): void
    {
        $po = $this->createReadyPo(
            categoryName: 'Consumables',
            poNumber: 'PO-2026-0042',
            description: 'Bond paper A4',
        );

        $service = app(PurchaseOrderFastPdfExportService::class);
        $pages = $service->buildPages($po->fresh(['orderedLines.item', 'purchaseRequest']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('PO-2026-0042', $pages[0]['po_no']);
        $this->assertSame('Bond paper A4', $pages[0]['lines'][0]['description']);
        $this->assertSame('25.50', $pages[0]['lines'][0]['unit_cost']);
        $this->assertSame('76.50', $pages[0]['lines'][0]['amount']);
        $this->assertTrue($pages[0]['include_footer']);

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('PO-2026-0042', $html);
        $this->assertStringContainsString('Bond paper A4', $html);
        $this->assertStringContainsString('Supplier : Supplier Co.', $html);
        $this->assertStringContainsString('hdr-supplier', $html);
        $this->assertStringContainsString('sig-line-cell', $html);
        $this->assertStringContainsString('Supplier Co.', $html);
        $this->assertStringContainsString('Fund Cluster : ___________________________________', $html);
        $this->assertStringContainsString('___________________________', $html);
        $this->assertStringNotContainsString('class="underline"', $html);
        $this->assertStringContainsString('PURCHASE ORDER', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 8mm', $html);
        $this->assertStringContainsString('width: 12.8%', $html);
        $this->assertStringContainsString('width: 33.3%', $html);
        $this->assertStringNotContainsString('Appendix 61', $html);
        $this->assertStringNotContainsString('>153<', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
        $this->assertStringContainsString('(Total Amount in Words)', $html);
        $this->assertStringContainsString('76.50', $html);
        $this->assertStringContainsString('text-align: center', $html);
        $this->assertStringContainsString('entity-line', $html);
        $this->assertStringContainsString('address-cell', $html);
        $this->assertStringContainsString('acct-top', $html);
        $this->assertStringContainsString('acct-mid', $html);
        $this->assertStringContainsString('border-bottom: none', $html);
        $this->assertMatchesRegularExpression('/\.gentlemen\s*\{[^}]*border-bottom:\s*none/s', $html);
        $this->assertDoesNotMatchRegularExpression('/\.gentlemen\s*\{[^}]*border-top:\s*none/s', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos);
    }

    public function test_fast_pdf_continues_on_second_page_when_lines_exceed_max_rows(): void
    {
        $po = $this->createReadyPo(
            categoryName: 'Consumables',
            poNumber: 'PO-2026-0099',
            description: 'Line 1 item',
        );

        $service = app(PurchaseOrderFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();
        $categoryId = $po->purchaseRequest->item_category_id;

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create(['item_category_id' => $categoryId]);
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
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(2, $pages);
        $this->assertSame('PO-2026-0099', $pages[0]['po_no']);
        $this->assertSame('PO-2026-0099 (Cont. 2)', $pages[1]['po_no']);
        $this->assertFalse($pages[0]['include_footer']);
        $this->assertTrue($pages[1]['include_footer']);
        $this->assertSame('', $pages[0]['total_amount_in_words']);
        $this->assertNotSame('', $pages[1]['total_amount_in_words']);
        $this->assertStringContainsString('(Cont. 2)', $html);
        $this->assertStringContainsString('Line '.($maxRows + 1).' item', $html);
    }

    public function test_fast_pdf_download_returns_pdf_bytes(): void
    {
        $po = $this->createReadyPo(
            categoryName: 'PPE',
            poNumber: 'PO-2026-0100',
            description: 'Safety helmet',
        );

        $response = app(PurchaseOrderFastPdfExportService::class)
            ->download($po->fresh(['orderedLines.item', 'purchaseRequest']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_fast_pdf_includes_supplier_with_slash_in_name(): void
    {
        $po = $this->createReadyPo(
            categoryName: 'Consumables',
            poNumber: 'PO-2026-SLASH',
            description: 'Bond paper',
        );
        $po->update(['supplier_name' => 'PS-DBM / Authorized Supplier']);

        $service = app(PurchaseOrderFastPdfExportService::class);
        $pages = $service->buildPages($po->fresh(['orderedLines.item', 'purchaseRequest']));
        $html = $service->renderHtml($pages, forDomPdf: true, showGeneratedOn: false);
        $pdf = $service->download($po->fresh(['orderedLines.item', 'purchaseRequest']))->getContent();

        $this->assertSame('PS-DBM / Authorized Supplier', $pages[0]['supplier']);
        $this->assertStringContainsString('Supplier : PS-DBM / Authorized Supplier', $html);
        $this->assertStringContainsString('hdr-supplier', $html);
        $this->assertStringContainsString('PS-DBM / Authorized Supplier', $html);
        $this->assertStringContainsString('sig-line-cell', $html);

        $foundInPdf = str_contains($pdf, 'PS-DBM') || str_contains($pdf, 'Authorized');
        if (! $foundInPdf && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                foreach ([
                    static fn (string $data): string|false => @gzuncompress($data),
                    static fn (string $data): string|false => @gzinflate($data),
                ] as $decoder) {
                    $decoded = $decoder($stream);
                    if (is_string($decoded) && (str_contains($decoded, 'PS-DBM') || str_contains($decoded, 'Authorized'))) {
                        $foundInPdf = true;
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($foundInPdf, 'Supplier name must be present in DomPDF output streams.');
    }

    public function test_fast_pdf_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $po = $this->createReadyPo(
            categoryName: 'Consumables',
            poNumber: 'PO-2026-0101',
            description: 'Folder long',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $po->purchaseRequest?->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.purchase-order.pdf', $po));

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
        $po = $this->createReadyPo(
            categoryName: $categoryName,
            poNumber: 'PO-2026-0200',
            description: "Item for {$categoryName}",
        );

        $pages = app(PurchaseOrderFastPdfExportService::class)
            ->buildPages($po->fresh(['orderedLines.item', 'purchaseRequest']));

        $this->assertCount(1, $pages);
        $this->assertSame("Item for {$categoryName}", $pages[0]['lines'][0]['description']);
        $this->assertNotSame('', $pages[0]['lines'][0]['unit_cost']);
    }

    public function test_export_visible_when_missing_fields_empty(): void
    {
        $po = $this->createReadyPo(
            categoryName: 'Consumables',
            poNumber: 'PO-2026-0300',
            description: 'Ready line',
        );

        $this->assertSame([], $po->fresh()->missingFields());
    }

    protected function createReadyPo(
        string $categoryName,
        string $poNumber,
        string $description,
    ): PurchaseOrder {
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
            'requested_by_name' => 'Juan Dela Cruz',
            'approved_by_name' => 'Maria Santos',
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
            'supplier_tin' => '123-456-789',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'delivery_term' => '15 days',
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
