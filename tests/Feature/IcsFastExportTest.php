<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\IcsFastExcelExportService;
use App\Services\IcsFastPdfExportService;
use App\Services\ParFastExcelExportService;
use App\Services\ParFastPdfExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class IcsFastExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_title_ics_no_item_and_signature_labels(): void
    {
        $issuance = $this->createSemiExpendableIssuance(
            serial: '2026-01-0026',
            itemName: 'Office Chair',
            itemCode: 'SE-001',
        );

        $service = app(IcsFastPdfExportService::class);
        $pages = $service->buildPages(collect([$issuance->fresh([
            'item',
            'office',
            'department',
            'issuedBy',
            'issuedTo',
            'batch',
            'requisition',
            'consolidatedRequisition',
        ])]));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('2026-01-0026', $pages[0]['ics_no']);
        $this->assertSame(30, $pages[0]['min_detail_rows']);
        $this->assertCount(1, $pages[0]['lines']);
        $this->assertSame('Office Chair', $pages[0]['lines'][0]['description']);
        $this->assertSame('SE-001', $pages[0]['lines'][0]['inventory_item_no']);

        $entityName = (string) config('owwa_export_standards.entity_name');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('INVENTORY CUSTODIAN SLIP', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('2026-01-0026', $html);
        $this->assertStringContainsString('Office Chair', $html);
        $this->assertStringContainsString('SE-001', $html);
        $this->assertStringContainsString('Estimated Useful Life', $html);
        $this->assertStringContainsString('Received from:', $html);
        $this->assertStringContainsString('Received by:', $html);
        $this->assertStringContainsString('Signature Over Printed Name', $html);
        $this->assertStringContainsString('Position/Office', $html);
        $this->assertStringContainsString('border-top: none', $html);
        $this->assertStringContainsString('sig-left', $html);
        $this->assertStringContainsString('font-weight: bold', $html);
        $this->assertStringContainsString('text-align: right', $html);
        $this->assertStringContainsString('line-no', $html);
        $this->assertDoesNotMatchRegularExpression('/class="meta-left"[^>]*colspan="2"/', $html);
        $this->assertStringContainsString('width: 10%', $html);
        $this->assertStringContainsString('width: 29%', $html);
        $this->assertStringContainsString('width: 17%', $html);
        $this->assertStringContainsString('height: 14px', $html);
        $this->assertStringContainsString('margin: 14mm 12mm 12mm 12mm', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: -12mm', $html);
        $this->assertStringContainsString('size: A4 portrait', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
        $this->assertStringNotContainsString('Appendix 59', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos, 'Generated on footer must be declared before page content tables.');
    }

    public function test_fast_pdf_pads_to_thirty_rows_and_grows_when_lines_exceed_template(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0200',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $service = app(IcsFastPdfExportService::class);
        $minRows = $service->minDetailRows();
        $this->assertSame(30, $minRows);

        $short = Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => Item::factory()->create([
                'item_category_id' => $category->id,
                'name' => 'Short slip item',
                'item_code' => 'SE-100',
            ])->id,
            'quantity' => 1,
            'unit_cost' => 500,
            'amount' => 500,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-01-0300',
            'issued_by' => $custodian->id,
        ]));

        $shortPages = $service->buildPages(collect([$short]));
        $this->assertSame(30, $shortPages[0]['min_detail_rows']);
        $this->assertCount(1, $shortPages[0]['lines']);

        $lineCount = $minRows + 1;
        $issuances = collect();

        for ($i = 1; $i <= $lineCount; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'name' => "Line {$i} item",
                'item_code' => 'SE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
            $issuances->push(Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
                'requisition_id' => $requisition->id,
                'office_id' => $office->id,
                'item_id' => $item->id,
                'quantity' => $i,
                'unit_cost' => 10,
                'amount' => 10 * $i,
                'issuance_date' => now()->toDateString(),
                'reference_code' => '2026-01-0400',
                'issued_by' => $custodian->id,
            ])));
        }

        $pages = $service->buildPages($issuances);
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertCount($lineCount, $pages[0]['lines']);
        $this->assertSame($lineCount, $pages[0]['min_detail_rows']);
        $this->assertStringContainsString('Line '.$lineCount.' item', $html);
        $this->assertStringContainsString('Line 1 item', $html);
        $this->assertStringContainsString('Received from:', $html);
        $this->assertStringContainsString('Received by:', $html);
        $this->assertStringContainsString('Signature Over Printed Name', $html);
    }

    public function test_semi_expendable_issuance_pdf_route_returns_fast_pdf(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createSemiExpendableIssuance(
            serial: '2026-01-0500',
            itemName: 'Filing Cabinet',
            itemCode: 'SE-050',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance.ics-fast-pdf', $issuance));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'ICS-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_semi_expendable_issuance_excel_route_returns_fast_xlsx(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createSemiExpendableIssuance(
            serial: '2026-01-0550',
            itemName: 'Metal Shelf',
            itemCode: 'SE-055',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance.ics-fast-xlsx', $issuance));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'ICS-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_generic_issuance_pdf_route_uses_ics_fast_for_semi_expendable(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createSemiExpendableIssuance(
            serial: '2026-01-0600',
            itemName: 'Whiteboard',
            itemCode: 'SE-060',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance', $issuance).'?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'ICS-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_ppe_issuance_does_not_use_ics_fast_routes(): void
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
            'reference_code' => '2026-01-0700',
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
            'reference_code' => '2026-01-0701',
            'issued_by' => $custodian->id,
        ]));

        $this->mock(IcsFastPdfExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });
        $this->mock(IcsFastExcelExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });

        $this->mock(ParFastPdfExportService::class, function ($mock): void {
            $mock->shouldReceive('download')
                ->once()
                ->andReturn(new Response('%PDF-1.4 par-fast', 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="PAR-fast-2026-01-0701.pdf"',
                ]));
        });
        $this->mock(ParFastExcelExportService::class, function ($mock): void {
            $mock->shouldReceive('download')
                ->once()
                ->andReturn(new StreamedResponse(static function (): void {
                    echo 'xlsx';
                }, 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="PAR-fast-2026-01-0701.xlsx"',
                ]));
        });

        $pdfResponse = $this->actingAs($custodian)
            ->get(route('owwa.export.issuance', $issuance).'?format=pdf');
        $pdfResponse->assertOk();
        $this->assertSame('%PDF-1.4 par-fast', $pdfResponse->getContent());

        $xlsxResponse = $this->actingAs($custodian)
            ->get(route('owwa.export.issuance', $issuance));
        $xlsxResponse->assertOk();

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.ics-fast-pdf', $issuance))
            ->assertStatus(422);

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.ics-fast-xlsx', $issuance))
            ->assertStatus(422);
    }

    protected function createSemiExpendableIssuance(
        string $serial,
        string $itemName,
        string $itemCode,
    ): Issuance {
        $office = Office::factory()->create(['code' => 'RO4A']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => $itemName,
            'item_code' => $itemCode,
            'description' => null,
            'unit' => 'unit',
            'estimated_useful_life' => '3 years',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'name' => 'Supply Custodian',
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0100',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        return Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_cost' => 1500,
            'amount' => 3000,
            'issuance_date' => now()->toDateString(),
            'reference_code' => $serial,
            'issued_by' => $custodian->id,
            'custodian_printed_name' => 'Supply Custodian',
            'estimated_useful_life' => '3 years',
        ]));
    }
}
