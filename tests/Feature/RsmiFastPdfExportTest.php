<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Services\RsmiFastPdfExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

class RsmiFastPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_title_serial_item_and_recap_labels(): void
    {
        $issuance = $this->createConsumableIssuance(
            serial: '2026-09-0026',
            itemName: 'Bond Paper A4',
            itemCode: 'CON-001',
            risNumber: '2026-09-0100',
        );

        $service = app(RsmiFastPdfExportService::class);
        $pages = $service->buildPages(collect([$issuance->fresh([
            'item',
            'office',
            'department',
            'requisition',
            'consolidatedRequisition',
            'batch',
            'issuedBy',
        ])]));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('2026-09-0026', $pages[0]['serial_no']);
        $this->assertTrue($pages[0]['include_footer']);
        $this->assertSame('Bond Paper A4', $pages[0]['lines'][0]['item']);
        $this->assertSame('CON-001', $pages[0]['lines'][0]['stock_no']);
        $this->assertSame('2026-09-0100', $pages[0]['lines'][0]['ris_no']);
        $this->assertNotEmpty($pages[0]['recap_lines']);

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('REPORT OF SUPPLIES AND MATERIALS ISSUED', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('2026-09-0026', $html);
        $this->assertStringContainsString('Bond Paper A4', $html);
        $this->assertStringContainsString('CON-001', $html);
        $this->assertStringContainsString('Recapitulation:', $html);
        $this->assertStringContainsString('UACS Object Code', $html);
        $this->assertStringContainsString('I hereby certify to the correctness of the above information.', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 8mm', $html);
        $this->assertStringContainsString('margin: 12mm 12mm 20mm 12mm', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
        $this->assertStringNotContainsString('Appendix 64', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos, 'Generated on footer must be declared before page content tables.');
    }

    public function test_fast_pdf_expands_detail_and_recap_rows_when_lines_exceed_template(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-09-0200',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $service = app(RsmiFastPdfExportService::class);
        $minRows = $service->minDetailRows();
        $lineCount = $minRows + 1;
        $issuances = collect();

        for ($i = 1; $i <= $lineCount; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'name' => "Line {$i} item",
                'item_code' => 'CON-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
            $issuances->push(Issuance::query()->create([
                'requisition_id' => $requisition->id,
                'office_id' => $office->id,
                'item_id' => $item->id,
                'quantity' => $i,
                'unit_cost' => 10,
                'amount' => 10 * $i,
                'issuance_date' => now()->toDateString(),
                'reference_code' => '2026-09-0300',
                'issued_by' => $custodian->id,
            ]));
        }

        $pages = $service->buildPages($issuances);
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('2026-09-0300', $pages[0]['serial_no']);
        $this->assertTrue($pages[0]['include_footer']);
        $this->assertCount($lineCount, $pages[0]['lines']);
        $this->assertCount($lineCount, $pages[0]['recap_lines']);
        $this->assertSame($lineCount, $pages[0]['min_detail_rows']);
        $this->assertSame($lineCount, $pages[0]['min_recap_rows']);
        $this->assertStringNotContainsString('(Cont. 2)', $html);
        $this->assertStringContainsString('Line '.$lineCount.' item', $html);
        $this->assertStringContainsString('Line 1 item', $html);
    }

    public function test_consumable_issuance_pdf_route_returns_fast_pdf(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createConsumableIssuance(
            serial: '2026-09-0400',
            itemName: 'Folder long',
            itemCode: 'CON-040',
            risNumber: '2026-09-0401',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance.rsmi-fast-pdf', $issuance));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RSMI-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_rsmi_date_range_pdf_uses_fast_path(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createConsumableIssuance(
            serial: '2026-09-0500',
            itemName: 'Ballpen',
            itemCode: 'CON-050',
            risNumber: '2026-09-0501',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)->get(route('owwa.export.bulk.issuances.rsmi', [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'format' => 'pdf',
            'category' => $issuance->item->item_category_id,
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RSMI-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_ppe_issuance_pdf_does_not_use_rsmi_fast_service(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Laptop']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-09-0600',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        $issuance = Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'amount' => 1000,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-09-0601',
            'issued_by' => $custodian->id,
        ]));

        $this->mock(RsmiFastPdfExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });

        $this->mock(OwwaTemplateExportService::class, function ($mock): void {
            $mock->shouldReceive('downloadIssuancePdf')
                ->once()
                ->andReturn(new Response('%PDF-1.4 official', 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="PAR-2026-09-0601.pdf"',
                ]));
        });

        $response = $this->actingAs($custodian)
            ->get(route('owwa.export.issuance', $issuance).'?format=pdf');

        $response->assertOk();
        $this->assertSame('%PDF-1.4 official', $response->getContent());
        $this->assertStringContainsString(
            'PAR-2026-09-0601.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    protected function createConsumableIssuance(
        string $serial,
        string $itemName,
        string $itemCode,
        string $risNumber,
    ): Issuance {
        $office = Office::factory()->create(['code' => 'RO4A']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => $itemName,
            'item_code' => $itemCode,
            'unit' => 'ream',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'name' => 'Supply Custodian',
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => $risNumber,
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        return Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 16,
            'unit_cost' => 185,
            'amount' => 2960,
            'issuance_date' => now()->toDateString(),
            'reference_code' => $serial,
            'issued_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
        ]);
    }
}
