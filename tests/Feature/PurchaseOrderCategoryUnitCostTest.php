<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\PurchaseOrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderCategoryUnitCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumable_po_submit_accepts_any_unit_cost(): void
    {
        $po = $this->draftPoWithCost('Consumables', 75000);

        $submitted = app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));

        $this->assertTrue($submitted->isPendingApproval());
        $this->assertNotNull($submitted->number);
    }

    public function test_semi_expendable_po_submit_rejects_cap_threshold(): void
    {
        $po = $this->draftPoWithCost('Semi-Expendable', 50000);

        try {
            app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Semi-expendable', $exception->errors()['unit_cost'][0] ?? '');
        }

        $po->refresh();
        $this->assertTrue($po->isUnsavedPoDraft());
        $this->assertNull($po->number);
        $this->assertNull($po->submitted_at);
    }

    public function test_semi_expendable_po_submit_accepts_cost_below_cap(): void
    {
        $po = $this->draftPoWithCost('Semi-Expendable', 49999.99);

        $submitted = app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));

        $this->assertTrue($submitted->isPendingApproval());
    }

    public function test_ppe_po_submit_rejects_cost_below_minimum(): void
    {
        $po = $this->draftPoWithCost('PPE', 49999.99);

        try {
            app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('PPE', $exception->errors()['unit_cost'][0] ?? '');
        }

        $po->refresh();
        $this->assertTrue($po->isUnsavedPoDraft());
        $this->assertNull($po->submitted_at);
    }

    public function test_ppe_po_submit_accepts_minimum_threshold(): void
    {
        $po = $this->draftPoWithCost('PPE', 50000);

        $submitted = app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));

        $this->assertTrue($submitted->isPendingApproval());
    }

    public function test_ppe_po_approve_rejects_cost_below_minimum(): void
    {
        $po = $this->draftPoWithCost('PPE', 50000);
        $workflow = app(PurchaseOrderWorkflowService::class);
        $workflow->submit($po->fresh(['lines']));

        $po->lines()->update([
            'unit_cost' => 10000,
            'amount' => 50000,
        ]);

        $this->expectException(ValidationException::class);
        $workflow->approve($po->fresh(['lines']));
    }

    public function test_void_draft_deletes_po_and_archives_pr_when_cost_out_of_range(): void
    {
        $po = $this->draftPoWithCost('Semi-Expendable', 55000);
        $prId = (int) $po->acquisition_paperwork_id;

        $paperwork = app(PurchaseOrderWorkflowService::class)->voidDraftAndArchivePr($po->fresh(['lines.item.category']));

        $this->assertTrue($paperwork->isArchived());
        $this->assertSame(AcquisitionPaperwork::PHASE_PR, $paperwork->phase);
        $this->assertNull($paperwork->po_number);
        $this->assertDatabaseMissing(PurchaseOrder::class, ['id' => $po->id]);
        $this->assertDatabaseHas(AcquisitionPaperwork::class, [
            'id' => $prId,
            'archived_at' => $paperwork->archived_at,
        ]);
    }

    public function test_void_draft_requires_out_of_range_unit_cost(): void
    {
        $po = $this->draftPoWithCost('Semi-Expendable', 12000);

        $this->expectException(ValidationException::class);
        app(PurchaseOrderWorkflowService::class)->voidDraftAndArchivePr($po->fresh(['lines.item.category']));
    }

    public function test_void_draft_rejects_submitted_purchase_order(): void
    {
        $submitted = $this->draftPoWithCost('Semi-Expendable', 12000);
        app(PurchaseOrderWorkflowService::class)->submit($submitted->fresh(['lines']));

        $submitted->lines()->update([
            'unit_cost' => 55000,
            'amount' => 275000,
        ]);

        try {
            app(PurchaseOrderWorkflowService::class)->voidDraftAndArchivePr(
                $submitted->fresh(['lines.item.category']),
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('unsaved draft', $exception->errors()['phase'][0] ?? '');
        }

        $this->assertDatabaseHas(PurchaseOrder::class, ['id' => $submitted->id]);
        $this->assertFalse($submitted->purchaseRequest()->first()?->isArchived() ?? true);
    }

    protected function draftPoWithCost(string $categoryName, float $unitCost): PurchaseOrder
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => $categoryName]);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Category unit cost coverage',
            'pr_date' => now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'unit',
            'quantity' => 5,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => 'Warehouse Road',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => $office->name,
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => 'COD',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => $unitCost,
            'amount' => round($unitCost * 5, 2),
        ]);

        return $po->fresh(['lines.item.category']) ?? $po;
    }
}
