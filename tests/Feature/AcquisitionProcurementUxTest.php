<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Actions\InspectionAcceptanceReportActions;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Paperwork\Actions\AcquisitionPaperworkActions;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionProcurementUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_pr_action_hidden_until_saved(): void
    {
        $paperwork = $this->createPrDraft();

        $this->assertNull($paperwork->pr_submitted_at);
        $this->assertFalse($this->actionVisible(AcquisitionPaperworkActions::approvePrAction(), $paperwork));

        app(AcquisitionPaperworkCompletionService::class)->submitPr($paperwork->fresh());
        $paperwork = $paperwork->fresh();

        $this->assertNotNull($paperwork->pr_submitted_at);
        $this->assertTrue($this->actionVisible(AcquisitionPaperworkActions::approvePrAction(), $paperwork));
    }

    public function test_export_pr_actions_hidden_until_required_fields_complete(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create();

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => '',
            'pr_date' => null,
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        $this->assertNotEmpty($paperwork->missingPrFields());
        $this->assertFalse($this->actionVisible(AcquisitionPaperworkActions::exportPrAction(), $paperwork));
        $this->assertFalse($this->actionVisible(AcquisitionPaperworkActions::exportPrPdfAction(), $paperwork));

        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $paperwork->update([
            'purpose' => 'Office supplies for regional use',
            'pr_date' => now()->toDateString(),
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        $this->assertSame([], $paperwork->fresh()->missingPrFields());
        $this->assertTrue($this->actionVisible(AcquisitionPaperworkActions::exportPrAction(), $paperwork->fresh()));
        $this->assertTrue($this->actionVisible(AcquisitionPaperworkActions::exportPrPdfAction(), $paperwork->fresh()));
    }

    public function test_receive_blocked_when_receive_date_is_in_the_future(): void
    {
        $iar = $this->createApprovedIar();
        $iar->update([
            'iar_date' => now()->subDays(3)->toDateString(),
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->addDay()->toDateString(),
        ]);

        $this->assertContains('receive date must be today or earlier', $iar->fresh()->missingFields());
        $this->assertFalse($this->actionVisible(
            InspectionAcceptanceReportActions::recordCustodyReceiptAction(),
            $iar->fresh(),
        ));

        $this->expectException(ValidationException::class);
        app(InspectionAcceptanceReportWorkflowService::class)->recordCustodyReceipts($iar->fresh());
    }

    public function test_receive_allowed_when_receive_date_is_today(): void
    {
        $iar = $this->createApprovedIar();
        $iar->update([
            'iar_date' => now()->subDays(3)->toDateString(),
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->toDateString(),
        ]);

        $this->assertTrue($this->actionVisible(
            InspectionAcceptanceReportActions::recordCustodyReceiptAction(),
            $iar->fresh(),
        ));

        $created = app(InspectionAcceptanceReportWorkflowService::class)->recordCustodyReceipts($iar->fresh());
        $this->assertNotEmpty($created);
        $this->assertTrue($iar->fresh()->isReceived());
    }

    public function test_pr_list_date_range_filter_limits_rows(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $inRange = $this->createPrDraft($office, $category, $custodian, now()->subDays(2)->toDateString());
        $outOfRange = $this->createPrDraft($office, $category, $custodian, now()->subDays(20)->toDateString());

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class, ['category' => $category->id])
            ->filterTable('date_range', [
                'from' => now()->subDays(5)->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$outOfRange]);
    }

    public function test_pr_list_date_range_filter_ignores_inverted_from_to(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $early = $this->createPrDraft($office, $category, $custodian, now()->subDays(20)->toDateString());
        $late = $this->createPrDraft($office, $category, $custodian, now()->subDays(2)->toDateString());

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class, ['category' => $category->id])
            ->filterTable('date_range', [
                'from' => now()->toDateString(),
                'until' => now()->subDays(5)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$early, $late]);
    }

    public function test_pr_list_does_not_bind_table_filters_to_url(): void
    {
        $attributes = (new \ReflectionProperty(ListAcquisitions::class, 'tableFilters'))
            ->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertSame([], $attributes);
    }

    public function test_bulk_procurement_export_returns_excel_for_date_range(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createPrDraft();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $this->actingAs($user)
            ->get(route('owwa.export.bulk.procurement', [
                'document_type' => 'pr',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'category' => $paperwork->item_category_id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_bulk_procurement_pdf_export_returns_pdf_for_single_record(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createPrDraft();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $paperwork->office_id,
        ]);

        $this->actingAs($user)
            ->get(route('owwa.export.bulk.procurement', [
                'document_type' => 'pr',
                'format' => 'pdf',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'category' => $paperwork->item_category_id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_bulk_procurement_pdf_export_merges_multiple_records_into_one_pdf(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $first = $this->createPrDraft();
        $second = $this->createPrDraft(
            Office::query()->find($first->office_id),
            ItemCategory::query()->find($first->item_category_id),
            User::query()->find($first->recorded_by),
        );

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $first->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.bulk.procurement', [
                'document_type' => 'pr',
                'format' => 'pdf',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'category' => $first->item_category_id,
            ]));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringNotContainsString('Download PR PDFs', $response->getContent());
        $this->assertGreaterThan(1, AcquisitionPaperwork::query()
            ->where('item_category_id', $first->item_category_id)
            ->whereDate('pr_date', '>=', now()->subDay()->toDateString())
            ->count());
        unset($second);
    }

    public function test_bulk_po_pdf_export_includes_technical_specification_sheet(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $po = $this->createDraftPo();
        $uniqueSpecs = 'BULK-PO-TECH-SPECS-'.uniqid();
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => $uniqueSpecs,
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update(['is_ordered' => true, 'unit_cost' => 10, 'amount' => 50]);
        app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));

        $spreadsheet = app(\App\Services\AcquisitionPaperworkPdfExportService::class)
            ->purchaseOrderFilledSpreadsheet($po->fresh(['orderedLines.item', 'purchaseRequest.itemCategory']));

        $this->assertGreaterThanOrEqual(2, $spreadsheet->getSheetCount());
        $foundTechSpec = false;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (str_contains((string) $sheet->getCell('A1')->getValue(), 'TECHNICAL SPECIFICATION')) {
                $foundTechSpec = true;
                $this->assertStringContainsString($uniqueSpecs, (string) $sheet->getCell('A3')->getValue());
            }
        }
        $this->assertTrue($foundTechSpec, 'PO spreadsheet must include Technical Specification sheet.');

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $po->purchaseRequest?->office_id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('owwa.export.bulk.procurement', [
                'document_type' => 'po',
                'format' => 'pdf',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'category' => $po->purchaseRequest?->item_category_id,
            ]));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertTrue(
            (bool) preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $pdf, $pageCountMatch)
            && (int) $pageCountMatch[1] >= 2,
            'Bulk PO PDF must include the form and Technical Specification pages.',
        );

        // Prefer extractable text when present (Dompdf). LibreOffice PDFs often use
        // CID/glyph encodings, so page count + spreadsheet sheet coverage is the gate.
        if (
            str_contains($pdf, '/Producer (DomPdf')
            || str_contains($pdf, '/Producer (Dompdf')
        ) {
            $this->assertTrue(
                $this->pdfBinaryContainsText($pdf, 'TECHNICAL SPECIFICATION')
                || $this->pdfBinaryContainsText($pdf, $uniqueSpecs),
                'Bulk PO PDF must include Technical Specification content.',
            );
        }
    }

    protected function pdfBinaryContainsText(string $pdf, string $needle): bool
    {
        $candidates = array_values(array_unique(array_filter([
            $needle,
            // LibreOffice often stores literal strings as UTF-16BE.
            "\xFE\xFF".$this->utf16Be($needle),
            $this->utf16Be($needle),
        ], fn (string $value): bool => $value !== '')));

        foreach ($candidates as $candidate) {
            if (str_contains($pdf, $candidate)) {
                return true;
            }
        }

        if (! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            return false;
        }

        foreach ($matches[1] as $stream) {
            foreach ([
                static fn (string $data): string|false => @gzuncompress($data),
                static fn (string $data): string|false => @gzinflate($data),
                static fn (string $data): string|false => @gzinflate(substr($data, 2)),
            ] as $decoder) {
                $decoded = $decoder($stream);
                if (! is_string($decoded) || $decoded === '') {
                    continue;
                }

                foreach ($candidates as $candidate) {
                    if (str_contains($decoded, $candidate)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function utf16Be(string $value): string
    {
        $encoded = @iconv('UTF-8', 'UTF-16BE//IGNORE', $value);

        return is_string($encoded) ? $encoded : '';
    }

    public function test_export_action_resolves_category_from_livewire_property(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        session()->put('active_item_category_id', $category->id);

        Livewire::actingAs($custodian)
            ->test(\App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Pages\ListInspectionAcceptanceReports::class, [
                'category' => $category->id,
            ])
            ->callAction(\Filament\Actions\Testing\TestAction::make('exportProcurementReport')->schemaComponent(true, 'content'), [
                'document_type' => 'iar',
                'export_format' => 'xlsx',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->toDateString(),
            ])
            ->assertSuccessful();
    }

    public function test_approve_po_and_iar_hidden_until_submitted(): void
    {
        $po = $this->createDraftPo();
        $this->assertNull($po->submitted_at);
        $this->assertFalse($this->actionVisible(PurchaseOrderActions::approveAction(), $po));

        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
        ]);
        $po->lines()->update(['is_ordered' => true, 'unit_cost' => 10, 'amount' => 50]);
        app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
        $this->assertTrue($this->actionVisible(PurchaseOrderActions::approveAction(), $po->fresh()));

        app(PurchaseOrderWorkflowService::class)->approve($po->fresh());
        $iar = app(InspectionAcceptanceReportWorkflowService::class)->createFromApprovedPo($po->fresh());
        $this->assertNull($iar->submitted_at);
        $this->assertFalse($this->actionVisible(InspectionAcceptanceReportActions::approveAction(), $iar));
    }

    /**
     * @param  \Filament\Actions\Action  $action
     */
    protected function actionVisible($action, mixed $record): bool
    {
        $action->record($record);

        return (bool) $action->isVisible();
    }

    protected function createPrDraft(
        ?Office $office = null,
        ?ItemCategory $category = null,
        ?User $user = null,
        ?string $prDate = null,
    ): AcquisitionPaperwork {
        $office ??= Office::factory()->create(['is_regional_supply' => true]);
        $category ??= ItemCategory::factory()->create(['name' => 'Consumables']);
        $user ??= User::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => $prDate ?? now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        return $paperwork->fresh();
    }

    protected function createDraftPo(): PurchaseOrder
    {
        $paperwork = $this->createPrDraft();
        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        return app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
    }

    protected function createApprovedIar(): InspectionAcceptanceReport
    {
        $po = $this->createDraftPo();
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update(['is_ordered' => true, 'unit_cost' => 10, 'amount' => 50]);

        $poService = app(PurchaseOrderWorkflowService::class);
        $poService->submit($po->fresh(['lines']));
        $poService->approve($po->fresh());

        $iar = app(InspectionAcceptanceReportWorkflowService::class)->createFromApprovedPo($po->fresh());
        $iar->update([
            'invoice_number' => 'INV100',
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->toDateString(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
            'iar_date' => now()->subDays(3)->toDateString(),
        ]);

        $iarService = app(InspectionAcceptanceReportWorkflowService::class);
        $iarService->submit($iar->fresh(['lines']));
        $iarService->approve($iar->fresh());

        return $iar->fresh(['lines']);
    }

    protected function acquisitionPaperworkTemplatesExist(): bool
    {
        $service = app(\App\Services\OwwaTemplateExportService::class);
        $category = ItemCategory::query()->first() ?? ItemCategory::factory()->create(['name' => 'Consumables']);

        try {
            $filename = $service->getTemplatePathForCategory('acquisition_paperwork', $category, 'pr');
            $service->requireTemplateAbsolutePath($filename);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
