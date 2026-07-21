<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcquisitionPaperworkQrLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ppe_paperwork_downloads_combined_qr_labels_pdf_after_custody_receipt(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $paperwork = $this->createReceivedPpePaperwork(quantity: 3);

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.acquisition-paperwork', $paperwork))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $units = InventoryUnit::query()
            ->whereIn('acquisition_id', $paperwork->fresh()->acquisitions->pluck('id'))
            ->get();

        $this->assertCount(3, $units);
        $this->assertTrue($units->every(fn (InventoryUnit $unit): bool => filled($unit->property_number)));
    }

    public function test_consumables_paperwork_qr_labels_route_returns_not_found(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $paperwork = $this->createReceivedConsumablesPaperwork();

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.acquisition-paperwork', $paperwork))
            ->assertNotFound();
    }

    public function test_non_custodian_cannot_download_paperwork_qr_labels(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $paperwork = $this->createReceivedPpePaperwork(quantity: 1);

        $this->actingAs($employee)
            ->get(route('owwa.qr-labels.acquisition-paperwork', $paperwork))
            ->assertForbidden();
    }

    public function test_unreceived_paperwork_qr_labels_route_returns_not_found(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $paperwork = $this->createCompletedPpePaperwork(quantity: 1);

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.acquisition-paperwork', $paperwork))
            ->assertNotFound();
    }

    public function test_five_unit_paperwork_downloads_qr_labels_pdf(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $paperwork = $this->createReceivedPpePaperwork(quantity: 5);

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.acquisition-paperwork', $paperwork))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $units = InventoryUnit::query()
            ->whereIn('acquisition_id', $paperwork->fresh()->acquisitions->pluck('id'))
            ->get();

        $this->assertCount(5, $units);
    }

    public function test_qr_labels_view_renders_two_column_table_layout(): void
    {
        $labels = collect(range(1, 5))->map(fn (int $index): array => [
            'qr_data_uri' => 'data:image/png;base64,iVBORw0KGgo=',
            'property_number' => "PPE-2026-000{$index}",
            'item_name' => 'Wall Clock',
            'office_name' => 'OWWA Regional Office IV-A',
            'unit_section' => 'OWWA Regional Office IV-A - Administrative Division',
            'sp_tag_no' => '',
            'property_number_label' => 'Property No.',
            'property_name_label' => 'Property',
            'description' => 'Analog wall clock',
            'end_user' => 'Unit Consolidator',
            'acquisition_cost' => '1500.00',
            'date_acquired' => '2026-01-15',
            'agency_line_1' => 'Republic of the Philippines',
            'agency_line_2' => 'OVERSEAS WORKERS WELFARE ADMINISTRATION',
            'agency_address' => 'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
        ]);

        $html = view('reports.qr-labels', [
            'title' => 'Unit QR labels — AP-TEST',
            'labels' => $labels,
        ])->render();

        $this->assertStringContainsString('class="label-grid"', $html);
        $this->assertStringContainsString('class="label-cell"', $html);
        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('OVERSEAS WORKERS WELFARE ADMINISTRATION', $html);
        $this->assertStringContainsString('Brgy. Parian, Calamba, Laguna', $html);
        $this->assertStringContainsString('PPE-2026-0001', $html);
        $this->assertStringContainsString('Wall Clock', $html);
        $this->assertStringNotContainsString('SP Tag No.', $html);
        $this->assertStringNotContainsString('Unit/Section', $html);
        $this->assertStringNotContainsString('End-user', $html);
        $this->assertStringNotContainsString('Acquisition Cost', $html);
        $this->assertSame(5, substr_count($html, 'class="label"'));
    }

    protected function createReceivedPpePaperwork(int $quantity): AcquisitionPaperwork
    {
        $paperwork = $this->createCompletedPpePaperwork($quantity);
        app(AcquisitionPaperworkCompletionService::class)->recordCustodyReceipts($paperwork->fresh());

        return $paperwork->fresh(['acquisitions.inventoryUnits', 'itemCategory']);
    }

    protected function createReceivedConsumablesPaperwork(): AcquisitionPaperwork
    {
        $paperwork = $this->createCompletedConsumablesPaperwork();
        app(AcquisitionPaperworkCompletionService::class)->recordCustodyReceipts($paperwork->fresh());

        return $paperwork->fresh(['acquisitions', 'itemCategory']);
    }

    protected function createCompletedPpePaperwork(int $quantity): AcquisitionPaperwork
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office equipment',
            'pr_date' => now(),
            'supplier' => 'Supplier Co.',
            'po_date' => now(),
            'iar_date' => now(),
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
        ]);

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit ?? 'piece',
            'quantity' => $quantity,
            'unit_cost' => 75000,
            'amount' => 75000 * $quantity,
        ]);

        $this->actingAs($user);

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $this->advancePaperworkThroughPoAndIar($paperwork->fresh());

        return $paperwork->fresh(['lines.item', 'itemCategory']);
    }

    protected function createCompletedConsumablesPaperwork(): AcquisitionPaperwork
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
            'supplier' => 'Supplier Co.',
            'po_date' => now(),
            'iar_date' => now(),
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
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

        $this->actingAs($user);

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $this->advancePaperworkThroughPoAndIar($paperwork->fresh());

        return $paperwork->fresh(['lines.item', 'itemCategory']);
    }

    protected function advancePaperworkThroughPoAndIar(AcquisitionPaperwork $paperwork): void
    {
        $poService = app(PurchaseOrderWorkflowService::class);
        $iarService = app(InspectionAcceptanceReportWorkflowService::class);
        $completion = app(AcquisitionPaperworkCompletionService::class);

        $po = $poService->createFromApprovedPr($paperwork->fresh(['lines']));
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
        ]);
        $po->lines()->each(function ($line): void {
            $line->update([
                'po_quantity' => (int) $line->pr_quantity,
                'amount' => round((int) $line->pr_quantity * (float) $line->unit_cost, 2),
            ]);
        });

        $completion->completePo($paperwork->fresh(['purchaseOrder.lines']));

        $po = $paperwork->fresh(['purchaseOrder'])?->purchaseOrder;
        $this->assertNotNull($po);

        $iar = $iarService->createFromApprovedPo($po->fresh());
        $iarDate = now()->startOfDay();
        $iar->update([
            'invoice_number' => 'INV'.(string) $paperwork->id,
            'invoice_date' => $iarDate->copy()->addDay()->toDateString(),
            'date_inspected' => $iarDate->copy()->addDays(2)->toDateString(),
            'date_received' => $iarDate->copy()->addDays(3)->toDateString(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
            'iar_date' => $iarDate->toDateString(),
        ]);

        $completion->completeIar($paperwork->fresh(['purchaseOrder.inspectionAcceptanceReport']));
    }
}
