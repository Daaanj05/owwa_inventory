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
