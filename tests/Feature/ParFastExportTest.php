<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\ParFastExcelExportService;
use App\Services\ParFastPdfExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParFastExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_title_par_and_signature_labels(): void
    {
        $issuance = $this->createPpeIssuance(
            serial: '2026-01-0026',
            itemName: 'Laptop',
            itemCode: 'PPE-001',
        );

        $service = app(ParFastPdfExportService::class);
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
        $this->assertSame('2026-01-0026', $pages[0]['par_no']);
        $this->assertSame(30, $pages[0]['min_detail_rows']);
        $this->assertCount(1, $pages[0]['lines']);
        $this->assertSame('Laptop', $pages[0]['lines'][0]['description']);
        $this->assertSame('PPE-001', $pages[0]['lines'][0]['property_number']);

        $entityName = (string) config('owwa_export_standards.entity_name');
        $this->assertStringContainsString($entityName, $html);
        $this->assertStringContainsString('PROPERTY ACKNOWLEDGMENT RECEIPT', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('2026-01-0026', $html);
        $this->assertStringContainsString('Laptop', $html);
        $this->assertStringContainsString('PPE-001', $html);
        $this->assertStringContainsString('Property Number', $html);
        $this->assertStringContainsString('Date Acquired', $html);
        $this->assertStringContainsString('Received by:', $html);
        $this->assertStringContainsString('Issued by:', $html);
        $this->assertStringContainsString('Signature over Printed Name of End-User', $html);
        $this->assertStringContainsString('Signature over Printed Name of Supply and/or Property Custodian', $html);
        $this->assertStringContainsString('Position/Office', $html);
        $this->assertStringContainsString('border-top: none', $html);
        $this->assertStringContainsString('sig-left', $html);
        $this->assertStringContainsString('font-weight: bold', $html);
        $this->assertStringContainsString('text-align: right', $html);
        $this->assertStringContainsString('line-no', $html);
        $this->assertDoesNotMatchRegularExpression('/class="meta-left"[^>]*colspan="2"/', $html);
        $this->assertStringContainsString('width: 11%', $html);
        $this->assertStringContainsString('width: 31%', $html);
        $this->assertStringContainsString('width: 19%', $html);
        $this->assertStringContainsString('height: 14px', $html);
        $this->assertStringContainsString('margin: 14mm 12mm 12mm 12mm', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: -12mm', $html);
        $this->assertStringContainsString('size: A4 portrait', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
        $this->assertStringNotContainsString('Appendix 71', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos, 'Generated on footer must be declared before page content tables.');
    }

    public function test_fast_pdf_pads_to_thirty_rows_and_grows_when_lines_exceed_template(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0200',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $service = app(ParFastPdfExportService::class);
        $minRows = $service->minDetailRows();
        $this->assertSame(30, $minRows);

        $short = Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => Item::factory()->create([
                'item_category_id' => $category->id,
                'name' => 'Short slip item',
                'item_code' => 'PPE-100',
                'description' => null,
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
                'item_code' => 'PPE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'description' => null,
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
        $this->assertStringContainsString('Received by:', $html);
        $this->assertStringContainsString('Issued by:', $html);
        $this->assertStringContainsString('Signature over Printed Name of End-User', $html);
    }

    public function test_ppe_issuance_pdf_route_returns_fast_pdf(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createPpeIssuance(
            serial: '2026-01-0500',
            itemName: 'Desktop Computer',
            itemCode: 'PPE-050',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance.par-fast-pdf', $issuance));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'PAR-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_ppe_issuance_excel_route_returns_fast_xlsx(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createPpeIssuance(
            serial: '2026-01-0550',
            itemName: 'Printer',
            itemCode: 'PPE-055',
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.issuance.par-fast-xlsx', $issuance));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'PAR-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_generic_issuance_pdf_route_uses_par_fast_for_ppe(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $issuance = $this->createPpeIssuance(
            serial: '2026-01-0600',
            itemName: 'Monitor',
            itemCode: 'PPE-060',
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
            'PAR-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_consumable_and_semi_expendable_do_not_use_par_fast_routes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
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

        $consumableCategory = ItemCategory::factory()->create(['name' => 'Consumables']);
        $consumable = Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => Item::factory()->create([
                'item_category_id' => $consumableCategory->id,
                'name' => 'Bond Paper',
            ])->id,
            'quantity' => 1,
            'unit_cost' => 10,
            'amount' => 10,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-01-0701',
            'issued_by' => $custodian->id,
        ]));

        $semiCategory = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $semi = Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => Item::factory()->create([
                'item_category_id' => $semiCategory->id,
                'name' => 'Office Chair',
            ])->id,
            'quantity' => 1,
            'unit_cost' => 500,
            'amount' => 500,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-01-0702',
            'issued_by' => $custodian->id,
        ]));

        $this->mock(ParFastPdfExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });
        $this->mock(ParFastExcelExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.par-fast-pdf', $consumable))
            ->assertStatus(422);

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.par-fast-xlsx', $consumable))
            ->assertStatus(422);

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.par-fast-pdf', $semi))
            ->assertStatus(422);

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuance.par-fast-xlsx', $semi))
            ->assertStatus(422);
    }

    protected function createPpeIssuance(
        string $serial,
        string $itemName,
        string $itemCode,
    ): Issuance {
        $office = Office::factory()->create(['code' => 'RO4A']);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => $itemName,
            'item_code' => $itemCode,
            'description' => null,
            'unit' => 'unit',
            'estimated_useful_life' => '5 years',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => $serial.'-req',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        return Issuance::withoutEvents(fn (): Issuance => Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'amount' => 1000,
            'issuance_date' => now()->toDateString(),
            'reference_code' => $serial,
            'issued_by' => $custodian->id,
        ]));
    }
}
