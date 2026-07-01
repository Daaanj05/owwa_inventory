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
        $this->assertSame(3, $units->pluck('property_number')->unique()->count());
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
        ]);

        $html = view('reports.qr-labels', [
            'title' => 'Unit QR labels — AP-TEST',
            'labels' => $labels,
        ])->render();

        $this->assertStringContainsString('class="label-grid"', $html);
        $this->assertStringContainsString('class="label-cell"', $html);
        $this->assertSame(3, substr_count($html, '<tr>'));
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

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());

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

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());

        return $paperwork->fresh(['lines.item', 'itemCategory']);
    }
}
