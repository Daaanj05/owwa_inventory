<?php

namespace Tests\Unit;

use App\Models\AiProcurementRun;
use App\Support\AiProcurementRunViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProcurementRunViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_record_includes_item_count_in_meta(): void
    {
        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonth(),
            'period_to' => now(),
            'summary' => 'Summary',
            'status' => 'approved',
        ]);

        $run->items()->create([
            'section' => 'urgent',
            'priority' => 'Medium',
            'item_name' => 'Zebra Marker',
            'office_name' => 'Main',
            'suggested_qty_min' => 2,
            'suggested_qty_max' => 2,
            'reason' => 'Medium stock',
        ]);
        $run->items()->create([
            'section' => 'urgent',
            'priority' => 'High',
            'item_name' => 'Bond Paper',
            'office_name' => 'Main',
            'suggested_qty_min' => 1,
            'suggested_qty_max' => 5,
            'reason' => 'Low stock',
        ]);
        $run->items()->create([
            'section' => 'urgent',
            'priority' => 'High',
            'item_name' => 'Alcohol',
            'office_name' => 'Main',
            'suggested_qty_min' => 3,
            'suggested_qty_max' => 3,
            'reason' => 'Critical',
        ]);
        $run->items()->create([
            'section' => 'replacement',
            'priority' => 'Medium',
            'item_name' => 'Office Chair',
            'suggested_qty_min' => null,
            'suggested_qty_max' => null,
            'reason' => 'Awaiting review',
        ]);

        $data = AiProcurementRunViewPresenter::forRecord($run->fresh(['items', 'creator']));

        $this->assertSame('Run #'.$run->id, $data['reference']);
        $this->assertSame('Approved', $data['status']['label']);
        $this->assertCount(4, $data['meta']);
        $this->assertSame('4 items', $data['meta'][3]['value']);
        $this->assertCount(4, $data['items']);
        $this->assertCount(3, $data['consumableItems']);
        $this->assertCount(1, $data['replacementItems']);
        $this->assertSame(['Alcohol', 'Bond Paper', 'Zebra Marker'], array_column($data['consumableItems'], 'item_name'));
        $this->assertSame(['High', 'High', 'Medium'], array_column($data['consumableItems'], 'priority'));
        $this->assertSame('1–5', $data['consumableItems'][1]['suggested_qty']);
        $this->assertSame('Office Chair', $data['replacementItems'][0]['item_name']);
        $this->assertArrayNotHasKey('workflowSteps', $data);
        $this->assertArrayNotHasKey('kpis', $data);
    }

    public function test_pending_status_label(): void
    {
        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'status' => 'pending',
        ]);

        $data = AiProcurementRunViewPresenter::forRecord($run);

        $this->assertSame('Pending', $data['status']['label']);
    }

    public function test_format_suggested_qty_handles_single_value(): void
    {
        $this->assertSame('12', AiProcurementRunViewPresenter::formatSuggestedQty(12, 12));
        $this->assertSame('—', AiProcurementRunViewPresenter::formatSuggestedQty(null, null));
    }
}
