<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\PurchaseRequestFastPdfExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestFastPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_header_fields_logos_and_blank_costs(): void
    {
        $paperwork = $this->createReadyPr(
            categoryName: 'Consumables',
            prNumber: '2026-01-0042',
            description: 'Bond paper A4',
            requestedBy: 'Juan Dela Cruz',
            approvedBy: 'Maria Santos',
        );

        $service = app(PurchaseRequestFastPdfExportService::class);
        $pages = $service->buildPages($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('2026-01-0042', $pages[0]['pr_no']);
        $this->assertSame('', $pages[0]['lines'][0]['unit_cost']);
        $this->assertSame('', $pages[0]['lines'][0]['total_cost']);
        $this->assertSame('Bond paper A4', $pages[0]['lines'][0]['description']);
        $this->assertTrue($pages[0]['include_footer']);

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('2026-01-0042', $html);
        $this->assertStringContainsString('Bond paper A4', $html);
        $this->assertStringContainsString('Purpose: Office supplies for regional use', $html);
        $this->assertStringContainsString('purpose-block', $html);
        $this->assertStringContainsString('purpose-line', $html);
        $this->assertStringNotContainsString('purpose-spacer', $html);
        $this->assertStringNotContainsString('purpose-cell', $html);
        $this->assertSame(1, substr_count($html, 'class="purpose-block"'));
        $this->assertStringContainsString('PURCHASE REQUEST', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 8mm', $html);
        $this->assertStringContainsString('font-weight: bold', $html);
        $this->assertStringContainsString('hdr-bold', $html);
        $this->assertStringContainsString('hdr-top', $html);
        $this->assertStringContainsString('hdr-bot', $html);
        $this->assertStringContainsString('date-ef-band', $html);
        $this->assertStringContainsString('width: 9.2%', $html);
        $this->assertStringContainsString('width: 13.1%', $html);
        $this->assertMatchesRegularExpression('/colspan="2"[^>]*date-ef-band|date-ef-band[^>]*colspan="2"/', $html);
        $this->assertStringNotContainsString('Appendix 60', $html);
        $this->assertStringNotContainsString('>151<', $html);
        $this->assertStringContainsString('Juan Dela Cruz', $html);
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos, 'Generated on footer must be declared before page content tables, not as a trailing content line.');
    }

    public function test_fast_pdf_continues_on_second_page_when_lines_exceed_max_rows(): void
    {
        $paperwork = $this->createReadyPr(
            categoryName: 'Consumables',
            prNumber: '2026-01-0099',
            description: 'Line 1 item',
        );

        $service = app(PurchaseRequestFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();
        $categoryId = $paperwork->item_category_id;

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create(['item_category_id' => $categoryId]);
            AcquisitionPaperworkLine::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'quantity' => $i,
            ]);
        }

        $pages = $service->buildPages($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(2, $pages);
        $this->assertSame('2026-01-0099', $pages[0]['pr_no']);
        $this->assertSame('2026-01-0099 (Cont. 2)', $pages[1]['pr_no']);
        $this->assertFalse($pages[0]['include_footer']);
        $this->assertTrue($pages[1]['include_footer']);
        $this->assertSame('', $pages[0]['purpose']);
        $this->assertNotSame('', $pages[1]['purpose']);
        $this->assertStringContainsString('(Cont. 2)', $html);
        $this->assertStringContainsString('Line '.($maxRows + 1).' item', $html);
    }

    public function test_fast_pdf_download_returns_pdf_bytes(): void
    {
        $paperwork = $this->createReadyPr(
            categoryName: 'PPE',
            prNumber: '2026-01-0100',
            description: 'Safety helmet',
        );

        $response = app(PurchaseRequestFastPdfExportService::class)
            ->download($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_fast_pdf_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReadyPr(
            categoryName: 'Consumables',
            prNumber: '2026-01-0101',
            description: 'Folder long',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.pr-fast-pdf', $paperwork));

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
        $paperwork = $this->createReadyPr(
            categoryName: $categoryName,
            prNumber: '2026-01-0200',
            description: "Item for {$categoryName}",
        );

        $pages = app(PurchaseRequestFastPdfExportService::class)
            ->buildPages($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));

        $this->assertCount(1, $pages);
        $this->assertSame("Item for {$categoryName}", $pages[0]['lines'][0]['description']);
        $this->assertSame('', $pages[0]['lines'][0]['unit_cost']);
    }

    protected function createReadyPr(
        string $categoryName,
        string $prNumber,
        string $description,
        ?string $requestedBy = null,
        ?string $approvedBy = null,
    ): AcquisitionPaperwork {
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
            'pr_number' => $prNumber,
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'requested_by_name' => $requestedBy,
            'approved_by_name' => $approvedBy,
            'requested_by_designation' => $requestedBy ? 'Supply Officer' : null,
            'approved_by_designation' => $approvedBy ? 'Regional Director' : null,
        ]);

        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $description,
            'unit' => 'pc',
            'quantity' => 3,
            'unit_cost' => 25.50,
            'amount' => 76.50,
        ]);

        return $paperwork->fresh();
    }
}
