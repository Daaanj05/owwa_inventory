<?php

namespace Tests\Unit;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\PhysicalCountCompletionService;
use App\Services\PhysicalCountPreloadService;
use App\Services\PhysicalCountScanService;
use App\Support\InventoryUnitQrPayload;
use App\Support\PhysicalCountSessionViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalCountSessionViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_steps_scan_only_before_book_load(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        $steps = PhysicalCountSessionViewPresenter::workflowSteps($session);

        $this->assertCount(4, $steps);
        $this->assertSame('pending', $steps[0]['state']);
        $this->assertSame('pending', $steps[1]['state']);
        $this->assertSame('Scan', $steps[0]['shortLabel']);
        $this->assertNotNull($steps[0]['url']);
        $this->assertNull($steps[3]['url']);
    }

    public function test_workflow_steps_book_loaded_marks_book_done(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();

        app(PhysicalCountScanService::class)->resolve($session, InventoryUnitQrPayload::encode($unit));
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session->fresh());

        $session = $session->fresh(['lines']);
        $steps = PhysicalCountSessionViewPresenter::workflowSteps($session);

        $this->assertSame('done', $steps[1]['state']);
        $this->assertSame('active', $steps[0]['state']);
        $this->assertFalse($session->countSummary()['scan_only'] ?? true);
    }

    public function test_workflow_steps_complete_marks_export_done(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);
        app(PhysicalCountScanService::class)->resolve($session, InventoryUnitQrPayload::encode($unit));

        $session = $session->fresh(['lines']);
        $session->update([
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Officer',
            'certified_by_printed_name' => 'Certifier',
            'approved_by_printed_name' => 'Approver',
            'verified_by_printed_name' => 'Verifier',
        ]);

        app(PhysicalCountCompletionService::class)->markComplete($session->fresh());

        $session = $session->fresh();
        $steps = PhysicalCountSessionViewPresenter::workflowSteps($session);

        $this->assertSame('done', $steps[2]['state']);
        $this->assertSame('done', $steps[3]['state']);
        $this->assertNotNull($steps[3]['url']);
        $this->assertNull($steps[0]['url']);
    }

    public function test_missing_for_complete_html_uses_line_breaks_and_title_case(): void
    {
        $session = new PhysicalCountSession([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'book_list_loaded' => false,
        ]);

        $html = PhysicalCountSessionViewPresenter::missingForCompleteHtml($session);

        $this->assertStringContainsString('<br>', (string) $html);
        $this->assertStringContainsString('Accountable Officer Name', (string) $html);
        $this->assertStringContainsString('Load Expected Assets (Book List)', (string) $html);
        $this->assertStringNotContainsString('Fund Cluster', (string) $html);
    }

    public function test_qr_workflow_steps_html_uses_numbered_title_case_labels(): void
    {
        $html = (string) PhysicalCountSessionViewPresenter::qrWorkflowStepsHtml();

        $this->assertStringContainsString('After You Save This Session:', $html);
        $this->assertStringContainsString('1. Load Expected Assets — pulls issued property numbers', $html);
        $this->assertStringContainsString('3. Scan With Phone — each tag found increments on-hand count.', $html);
    }

    public function test_lines_grouped_by_item_aggregates_tag_lines(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        $item = $unit->item;

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $session = $session->fresh(['lines.item']);
        $groups = PhysicalCountSessionViewPresenter::linesGroupedByItem($session);

        $this->assertCount(1, $groups);
        $this->assertSame($item->name, $groups[0]['item_name']);
        $this->assertSame(1, $groups[0]['tag_count']);
        $this->assertSame(1, $groups[0]['balance_per_card']);
        $this->assertSame(0, $groups[0]['on_hand_count']);
        $this->assertSame(-1, $groups[0]['variance']);
    }

    /**
     * @return array{0: PhysicalCountSession, 1: \App\Models\InventoryUnit}
     */
    protected function createPpeSessionWithUnit(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = \App\Models\Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create();

        $acquisition = \App\Models\Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $unit = $acquisition->inventoryUnits()->first();
        $this->assertNotNull($unit);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        return [$session, $unit];
    }
}
