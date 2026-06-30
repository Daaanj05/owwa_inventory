<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcquisitionPaperworkWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_paperwork_export_routes_return_spreadsheet(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createCompletedPaperwork();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.pr', $paperwork))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.po', $paperwork))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('owwa.export.acquisition-paperwork.iar', $paperwork))
            ->assertOk();
    }

    public function test_legacy_procurement_export_routes_still_work(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createCompletedPaperwork();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('owwa.export.procurement.pr', $paperwork))
            ->assertOk();
    }

    public function test_pr_export_maps_header_and_detail_cells(): void
    {
        $paperwork = $this->createCompletedPaperwork();

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPr($paperwork);

        $this->assertStringContainsString('Office supplies', (string) ($values['A33'] ?? ''));
        $this->assertArrayHasKey('A11', $values);
        $this->assertSame('5', (string) $values['D11']);
        $this->assertStringContainsString('CON-100', (string) ($values['A11'] ?? ''));
        $this->assertSame('25.50', (string) ($values['E11'] ?? ''));
        $this->assertSame('127.50', (string) ($values['F11'] ?? ''));
    }

    public function test_po_export_skips_accounting_header_cells(): void
    {
        $paperwork = $this->createCompletedPaperwork();

        $values = app(OwwaTemplateExportService::class)->cellValuesForAcquisitionPaperworkPo($paperwork);

        $this->assertArrayHasKey('A45', $values);
        $this->assertArrayNotHasKey('D45', $values);
        $this->assertArrayNotHasKey('A46', $values);
        $this->assertArrayNotHasKey('D46', $values);
        $this->assertArrayNotHasKey('D47', $values);
        $startRow = OwwaCellMapping::detailRowBase('PO');
        $this->assertArrayHasKey('F'.$startRow, $values);
    }

    public function test_pr_export_writes_line_items_into_spreadsheet_cells(): void
    {
        if (! $this->acquisitionPaperworkTemplatesExist()) {
            $this->markTestSkipped('OWWA acquisition paperwork templates are not installed.');
        }

        $paperwork = $this->createPaperworkDraft();
        $paperwork->update([
            'pr_number' => '2026-01-0001',
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
        ]);
        $paperwork->lines()->update(['unit_cost' => 25.50, 'amount' => 127.50]);

        $paperwork = $paperwork->fresh(['lines.item', 'office', 'department', 'itemCategory']);
        $service = app(OwwaTemplateExportService::class);
        $templateFilename = $service->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, 'pr');
        $cellValues = $service->cellValuesForAcquisitionPaperworkPr($paperwork);
        $spreadsheet = $service->renderFilledSpreadsheet($templateFilename, $cellValues);
        $sheet = $spreadsheet->getActiveSheet();
        $startRow = OwwaCellMapping::detailRowBase('PR');

        $this->assertStringContainsString('CON-100', (string) $sheet->getCell('A'.$startRow)->getValue());
        $this->assertSame('5', (string) $sheet->getCell('D'.$startRow)->getValue());
        $this->assertStringContainsString('Office supplies', (string) $sheet->getCell('A33')->getValue());
    }

    public function test_submit_and_approve_flow_assigns_serial_numbers(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $service->submitPr($paperwork->fresh());
        $paperwork = $paperwork->fresh();
        $this->assertSame(AcquisitionPaperwork::STATUS_PENDING_APPROVAL, $paperwork->pr_status);
        $this->assertNull($paperwork->pr_number);

        $service->approvePr($paperwork);
        $paperwork = $paperwork->fresh();
        $this->assertNotNull($paperwork->pr_number);
        $this->assertSame(AcquisitionPaperwork::STATUS_APPROVED, $paperwork->pr_status);

        $paperwork->update(['supplier' => 'Supplier Co.', 'po_date' => now()]);

        $service->submitPo($paperwork->fresh());
        $service->approvePo($paperwork->fresh());
        $paperwork = $paperwork->fresh();
        $this->assertNotNull($paperwork->po_number);

        $paperwork->update([
            'iar_date' => now(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
        ]);

        $service->submitIar($paperwork->fresh());
        $service->approveIar($paperwork->fresh());
        $paperwork = $paperwork->fresh();

        $this->assertNotNull($paperwork->iar_number);
        $this->assertTrue($paperwork->isIarApproved());
    }

    public function test_approve_pr_assigns_pr_number_when_reference_series_exists(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $service->submitPr($paperwork->fresh());

        $service->approvePr($paperwork->fresh());

        $paperwork = $paperwork->fresh();
        $this->assertNotNull($paperwork->pr_number);
        $this->assertNotSame('', $paperwork->pr_number);
        $this->assertSame(AcquisitionPaperwork::STATUS_APPROVED, $paperwork->pr_status);
    }

    public function test_submit_pr_blocked_without_department(): void
    {
        $paperwork = $this->createPaperworkDraft(includeDepartment: false);
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submitPr($paperwork);
    }

    public function test_submit_pr_blocked_without_unit_cost(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $paperwork->lines()->update(['unit_cost' => null, 'amount' => null]);
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submitPr($paperwork->fresh());
    }

    public function test_po_submit_blocked_before_pr_approval(): void
    {
        $paperwork = $this->createPaperworkDraft();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submitPo($paperwork);
    }

    public function test_record_custody_creates_acquisitions_linked_to_case(): void
    {
        $paperwork = $this->createCompletedPaperwork();
        $service = app(AcquisitionPaperworkCompletionService::class);

        $created = $service->recordCustodyReceipts($paperwork->fresh());

        $this->assertCount(1, $created);
        $this->assertDatabaseHas(Acquisition::class, [
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $paperwork->lines->first()->item_id,
            'quantity' => 5,
            'office_id' => $paperwork->office_id,
        ]);

        $paperwork = $paperwork->fresh();
        $this->assertTrue($paperwork->isReceived());
        $this->assertNotNull($paperwork->received_at);
        $this->assertSame('Received', $paperwork->workflowStatusLabel());
        $this->assertTrue(
            AcquisitionPaperwork::query()->whereKey($paperwork->id)->exists(),
            'Received paperwork should remain queryable in the acquisitions list.',
        );
        $this->assertStringContainsString('PO', (string) $created[0]->source);
        $this->assertStringContainsString('IAR', (string) $created[0]->source);
    }

    protected function createPaperworkDraft(bool $includeDepartment = true): AcquisitionPaperwork
    {
        $office = Office::factory()->create(['name' => 'OWWA RO', 'fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'item_code' => 'CON-100']);
        $user = User::factory()->create();
        $departmentId = null;

        if ($includeDepartment) {
            $departmentId = Department::query()->create([
                'office_id' => $office->id,
                'name' => 'Supply Section',
                'code' => 'SUP',
            ])->id;
        }

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'department_id' => $departmentId,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
        ]);

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit ?? 'piece',
            'quantity' => 5,
            'unit_cost' => 25.50,
            'amount' => 127.50,
        ]);

        return $paperwork->fresh(['lines.item', 'office', 'department', 'itemCategory']);
    }

    protected function acquisitionPaperworkTemplatesExist(): bool
    {
        return is_readable(storage_path('app/templates/Consumable/Acquisitions/Appendix 60 - PR.xls'));
    }

    protected function createCompletedPaperwork(): AcquisitionPaperwork
    {
        $paperwork = $this->createPaperworkDraft();

        $paperwork->update([
            'supplier' => 'Supplier Co.',
            'po_date' => now(),
            'iar_date' => now(),
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
        ]);

        $paperwork->lines()->update(['unit_cost' => 25.50, 'amount' => 127.50]);

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());

        return $paperwork->fresh(['lines.item', 'office', 'department', 'itemCategory']);
    }
}
