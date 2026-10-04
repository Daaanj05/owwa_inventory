<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\PurchaseRequestFastExcelExportService;
use App\Services\PurchaseRequestFastPdfExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\TestCase;

class PurchaseRequestFastExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_excel_spreadsheet_matches_fast_pdf_page_data(): void
    {
        $paperwork = $this->createReadyPr(
            prNumber: '2026-01-0042',
            description: 'Bond paper A4',
            requestedBy: 'Juan Dela Cruz',
            approvedBy: 'Maria Santos',
        );

        $pages = app(PurchaseRequestFastPdfExportService::class)
            ->buildPages($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));

        $spreadsheet = app(PurchaseRequestFastExcelExportService::class)->makeSpreadsheet($pages);
        $sheet = $spreadsheet->getActiveSheet();

        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $this->assertStringContainsString($entityName, (string) $sheet->getCell('A5')->getValue());
        $this->assertTrue($sheet->getStyle('A5')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('D5')->getFont()->getBold());
        $this->assertStringContainsString('Fund Cluster:', (string) $sheet->getCell('D5')->getValue());
        $this->assertStringContainsString('2026-01-0042', (string) $sheet->getCell('C6')->getValue());
        $this->assertTrue($sheet->getStyle('C6')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('E6')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('C7')->getFont()->getBold());
        $this->assertArrayHasKey('E6:F7', $sheet->getMergeCells());
        $this->assertSame(9.1, $sheet->getColumnDimension('E')->getWidth());
        $this->assertSame(13.0, $sheet->getColumnDimension('F')->getWidth());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('A6')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('A7')->getBorders()->getTop()->getBorderStyle());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('C6')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame(Border::BORDER_NONE, $sheet->getStyle('C7')->getBorders()->getTop()->getBorderStyle());
        $this->assertSame('Bond paper A4', (string) $sheet->getCell('C10')->getValue());
        $this->assertSame('', (string) $sheet->getCell('E10')->getValue());
        $this->assertSame('', (string) $sheet->getCell('F10')->getValue());
        $this->assertSame('PURCHASE REQUEST', (string) $sheet->getCell('C3')->getValue());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());

        $purposeValue = (string) $sheet->getCell('A32')->getValue();
        $this->assertStringContainsString('Purpose:', $purposeValue);
        $this->assertStringContainsString("\n", $purposeValue);

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_continues_with_second_sheet_when_lines_exceed_max_rows(): void
    {
        $paperwork = $this->createReadyPr(prNumber: '2026-01-0099', description: 'Line 1 item');
        $service = app(PurchaseRequestFastPdfExportService::class);
        $maxRows = $service->maxRowsPerPage();

        for ($i = 2; $i <= $maxRows + 1; $i++) {
            $item = Item::factory()->create(['item_category_id' => $paperwork->item_category_id]);
            AcquisitionPaperworkLine::query()->create([
                'acquisition_paperwork_id' => $paperwork->id,
                'item_id' => $item->id,
                'description' => "Line {$i} item",
                'unit' => 'pc',
                'quantity' => $i,
            ]);
        }

        $pages = $service->buildPages($paperwork->fresh(['lines.item', 'office', 'requestingOffice', 'department']));
        $spreadsheet = app(PurchaseRequestFastExcelExportService::class)->makeSpreadsheet($pages);

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertStringContainsString('2026-01-0099', $spreadsheet->getSheet(0)->getTitle());
        $this->assertStringContainsString('Cont', $spreadsheet->getSheet(1)->getTitle());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_fast_excel_route_downloads_for_authorized_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReadyPr(prNumber: '2026-01-0101', description: 'Folder long');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.pr-fast-xlsx', $paperwork));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
    }

    public function test_export_report_action_has_excel_and_pdf_only(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Acquisitions/Concerns/AcquisitionProcurementExportAction.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'xlsx' => 'Excel'", $source);
        $this->assertStringContainsString("'pdf' => 'PDF'", $source);
        $this->assertStringNotContainsString("'fast_xlsx'", $source);
        $this->assertStringNotContainsString("'fast_pdf'", $source);
    }

    public function test_bulk_pdf_and_excel_routes_use_lookalike_for_pr(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReadyPr(prNumber: '2026-01-0300', description: 'Bulk PR item');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $pdf = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'pr',
            'format' => 'pdf',
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'category' => $paperwork->item_category_id,
        ]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->actingAs($user)->get(route('owwa.export.bulk.procurement', [
            'document_type' => 'pr',
            'format' => 'xlsx',
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'category' => $paperwork->item_category_id,
        ]));
        $xlsx->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('content-type'),
        );
    }

    protected function createReadyPr(
        string $prNumber,
        string $description,
        string $categoryName = 'Consumables',
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
