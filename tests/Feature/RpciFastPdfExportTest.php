<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\RpciFastPdfExportService;
use App\Services\RpcppeFastPdfExportService;
use App\Services\RpcspFastPdfExportService;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RpciFastPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_pdf_html_includes_title_logos_lines_and_signatures(): void
    {
        $session = $this->createRpciSession();

        $service = app(RpciFastPdfExportService::class);
        $pages = $service->buildPages($session->fresh(['office', 'lines.item']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('Office Supplies Inventory', $pages[0]['inventory_type']);
        $this->assertSame('CON-001', $pages[0]['lines'][0]['stock_number']);
        $this->assertSame(-2, $pages[0]['lines'][0]['shortage_qty']);
        $this->assertSame(-51.0, $pages[0]['lines'][0]['shortage_value']);
        $this->assertSame('Chair Person', $pages[0]['certified_by']);
        $this->assertSame(21, $pages[0]['min_detail_rows']);

        $this->assertStringContainsString('REPORT ON THE PHYSICAL COUNT OF INVENTORIES', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('Office Supplies Inventory', $html);
        $this->assertStringContainsString('Bond Paper A4', $html);
        $this->assertStringContainsString('CON-001', $html);
        $this->assertStringContainsString('-51.00', $html);
        $this->assertStringContainsString('Certified Correct by:', $html);
        $this->assertStringContainsString('Signature over Printed Name of Inventory Committee Chair and Members', $html);
        $this->assertStringContainsString('Approved by:', $html);
        $this->assertStringContainsString('Verified by:', $html);
        $this->assertStringContainsString('Chair Person', $html);
        $this->assertStringContainsString('class="sig-name"', $html);
        $this->assertStringContainsString('border-bottom: 1px solid #000', $html);
        $this->assertStringNotContainsString('border-top: 1px solid #000', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: -12mm', $html);
        $this->assertStringContainsString('size: A4 landscape', $html);
        $this->assertStringContainsString('margin: 12.7mm 12.7mm 16mm 12.7mm', $html);
        $this->assertStringContainsString('owwa-form-logo.png', $html);
        $this->assertStringContainsString('bagong-pilipinas-form-logo.png', $html);
        $this->assertStringNotContainsString('Appendix 66', $html);

        $generatedPos = strpos($html, 'generated-on');
        $tableClosePos = strrpos($html, '</table>');
        $this->assertNotFalse($generatedPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertLessThan($tableClosePos, $generatedPos, 'Generated on footer must be declared before page content tables.');
    }

    public function test_fast_pdf_expands_detail_rows_when_lines_exceed_template(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCI-EXPAND',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'inventory_type_label' => 'Office Supplies Inventory',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => '2026-01-01',
            'certified_by_printed_name' => 'Chair Person',
            'approved_by_printed_name' => 'Agency Head',
            'verified_by_printed_name' => 'COA Rep',
            'completed_at' => now(),
        ]);

        $service = app(RpciFastPdfExportService::class);
        $minRows = $service->minDetailRows();
        $lineCount = $minRows + 1;

        for ($i = 1; $i <= $lineCount; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'name' => "Line {$i} item",
                'item_code' => 'CON-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);

            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'stock_number' => $item->item_code,
                'article' => $item->name,
                'balance_per_card' => 5,
                'on_hand_count' => 5,
            ]);
        }

        $pages = $service->buildPages($session->fresh(['office', 'lines.item']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertCount($lineCount, $pages[0]['lines']);
        $this->assertSame($lineCount, $pages[0]['min_detail_rows']);
        $this->assertStringContainsString('Line '.$lineCount.' item', $html);
        $this->assertStringContainsString('Line 1 item', $html);
        $this->assertStringContainsString('Certified Correct by:', $html);
    }

    public function test_rpci_fast_pdf_route_returns_pdf(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $session = $this->createRpciSession();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $session->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.physical-count.rpci-fast-pdf', $session));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RPCI-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_physical_count_pdf_query_uses_fast_path_for_rpci(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $session = $this->createRpciSession();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $session->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.physical-count', $session).'?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RPCI-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_rpcppe_fast_route_is_rejected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCPPE-001',
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'completed_at' => now(),
        ]);

        $this->mock(RpciFastPdfExportService::class, function ($mock): void {
            $mock->shouldNotReceive('download');
        });

        $response = $this->actingAs($user)
            ->get(route('owwa.export.physical-count.rpci-fast-pdf', $session));

        $response->assertStatus(422);
    }

    public function test_rpcppe_pdf_query_uses_fast_path(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $session = $this->createRpcppeSession();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $session->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.physical-count', $session).'?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RPCPPE-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_rpcppe_fast_pdf_html_uses_ppe_title_and_landscape(): void
    {
        $session = $this->createRpcppeSession();
        $service = app(RpcppeFastPdfExportService::class);
        $pages = $service->buildPages($session->fresh(['office', 'lines.item']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('OFFICE EQUIPMENT', $pages[0]['inventory_type']);
        $this->assertSame('PPE-001', $pages[0]['lines'][0]['property_number']);
        $this->assertSame(21, $pages[0]['min_detail_rows']);
        $this->assertFalse($pages[0]['nothing_to_report']);

        $this->assertStringContainsString('REPORT ON THE PHYSICAL COUNT OF PROPERTY, PLANT AND EQUIPMENT', $html);
        $this->assertStringContainsString('(Type of Property, Plant and Equipment)', $html);
        $this->assertStringContainsString('PROPERTY CARD', $html);
        $this->assertStringContainsString('PHYSICAL COUNT', $html);
        $this->assertStringContainsString('>REMARKS<', $html);
        $this->assertStringNotContainsString('REMAKS', $html);
        $this->assertStringNotContainsString('Appendix 73', $html);
        $this->assertStringNotContainsString('***nothing to report***', $html);
        $this->assertStringContainsString('size: A4 landscape', $html);
        $this->assertStringContainsString('margin: 12.7mm 12.7mm 16mm 12.7mm', $html);
        $this->assertStringContainsString('Generated on', $html);
        $this->assertStringContainsString('bottom: -12mm', $html);
    }

    public function test_rpcsp_fast_pdf_html_uses_semi_expendable_label(): void
    {
        $session = $this->createRpcspSession();
        $service = app(RpcspFastPdfExportService::class);
        $pages = $service->buildPages($session->fresh(['office', 'lines.item']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertSame('INFORMATION TECHNOLOGY EQUIPMENT', $pages[0]['inventory_type']);
        $this->assertSame('SE-001', $pages[0]['lines'][0]['property_number']);
        $this->assertSame(20, $pages[0]['min_detail_rows']);
        $this->assertFalse($pages[0]['nothing_to_report']);

        $this->assertStringContainsString('REPORT ON THE PHYSICAL COUNT OF SEMI-EXPENDABLE PROPERTY', $html);
        $this->assertStringContainsString('(Type of Semi-expendable)', $html);
        $this->assertStringNotContainsString('Type of Property, Plant and Equipment', $html);
        $this->assertStringContainsString('Semi-expendable Property No.', $html);
        $this->assertStringContainsString('On Hand per Card', $html);
        $this->assertStringContainsString('SE-001', $html);
        $this->assertStringNotContainsString('***nothing to report***', $html);
        $this->assertStringNotContainsString('Annex A.8', $html);
        $this->assertStringContainsString('border: 0.75px solid #000', $html);
        $this->assertStringNotContainsString('dotted', $html);
        $this->assertStringContainsString('size: A4 landscape', $html);
        $this->assertStringContainsString('margin: 12.7mm 12.7mm 16mm 12.7mm', $html);
    }

    public function test_empty_rpcsp_page_shows_nothing_to_report(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCSP-EMPTY',
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'completed_at' => now(),
        ]);

        $service = app(RpcspFastPdfExportService::class);
        $pages = $service->buildPages($session->fresh(['office', 'lines.item']));
        $html = $service->renderHtml($pages, forDomPdf: false, showGeneratedOn: true);

        $this->assertCount(1, $pages);
        $this->assertTrue($pages[0]['nothing_to_report']);
        $this->assertSame(20, $pages[0]['min_detail_rows']);
        $this->assertStringContainsString('***nothing to report***', $html);
        $this->assertStringContainsString('(Type of Semi-expendable)', $html);
    }

    public function test_rpcsp_pdf_query_uses_fast_path(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $session = $this->createRpcspSession();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $session->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.physical-count.rpcsp-fast-pdf', $session));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'RPCSP-fast',
            (string) $response->headers->get('content-disposition'),
        );
    }

    protected function createRpciSession(): PhysicalCountSession
    {
        $office = Office::factory()->create(['name' => 'OWWA Regional Office IV-A']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper A4',
            'item_code' => 'CON-001',
            'unit' => 'ream',
            'description' => 'A4 70gsm',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-RPCI-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 25.50,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCI-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => '2026-06-30',
            'inventory_type_label' => 'Office Supplies Inventory',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => '2026-01-01',
            'certified_by_printed_name' => 'Chair Person',
            'approved_by_printed_name' => 'Agency Head',
            'verified_by_printed_name' => 'COA Rep',
            'completed_at' => now(),
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'stock_number' => $item->item_code,
            'article' => $item->name,
            'description' => $item->description,
            'unit_of_measure' => $item->unit,
            'balance_per_card' => 10,
            'on_hand_count' => 8,
        ]);

        return $session->fresh(['office', 'lines.item']);
    }

    protected function createRpcppeSession(): PhysicalCountSession
    {
        $office = Office::factory()->create(['name' => 'OWWA Regional Office IV-A']);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office Chair',
            'item_code' => 'PPE-001',
            'unit' => 'unit',
            'ppe_type' => PpePropertyType::OfficeEquipment,
            'property_class' => null,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCPPE-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'ppe_type' => PpePropertyType::OfficeEquipment,
            'count_date' => '2026-06-30',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => '2026-01-01',
            'certified_by_printed_name' => 'Chair Person',
            'approved_by_printed_name' => 'Agency Head',
            'verified_by_printed_name' => 'COA Rep',
            'completed_at' => now(),
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'property_number' => 'PPE-001',
            'article' => $item->name,
            'description' => 'Executive chair',
            'unit_of_measure' => $item->unit,
            'balance_per_card' => 2,
            'on_hand_count' => 2,
        ]);

        return $session->fresh(['office', 'lines.item']);
    }

    protected function createRpcspSession(): PhysicalCountSession
    {
        $office = Office::factory()->create(['name' => 'OWWA Regional Office IV-A']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Laptop',
            'item_code' => 'SE-001',
            'unit' => 'unit',
            'property_class' => ItemPropertyClass::InformationTechnology,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-RPCSP-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::InformationTechnology,
            'count_date' => '2026-06-30',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => '2026-01-01',
            'certified_by_printed_name' => 'Chair Person',
            'approved_by_printed_name' => 'Agency Head',
            'verified_by_printed_name' => 'COA Rep',
            'completed_at' => now(),
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'property_number' => 'SE-001',
            'article' => $item->name,
            'description' => 'Notebook computer',
            'unit_of_measure' => $item->unit,
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        return $session->fresh(['office', 'lines.item']);
    }
}
