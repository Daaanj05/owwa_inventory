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
            'priority' => 'High',
            'item_name' => 'Bond Paper',
            'office_name' => 'Main',
            'suggested_qty_min' => 1,
            'suggested_qty_max' => 5,
            'reason' => 'Low stock',
        ]);

        $data = AiProcurementRunViewPresenter::forRecord($run->fresh(['items', 'creator']));

        $this->assertSame('Run #'.$run->id, $data['reference']);
        $this->assertSame('Approved', $data['status']['label']);
        $this->assertCount(4, $data['meta']);
        $this->assertSame('1 item', $data['meta'][3]['value']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Bond Paper', $data['items'][0]['item_name']);
        $this->assertSame('1–5', $data['items'][0]['suggested_qty']);
        $this->assertArrayNotHasKey('workflowSteps', $data);
        $this->assertArrayNotHasKey('kpis', $data);
    }

    public function test_format_suggested_qty_handles_single_value(): void
    {
        $this->assertSame('12', AiProcurementRunViewPresenter::formatSuggestedQty(12, 12));
        $this->assertSame('—', AiProcurementRunViewPresenter::formatSuggestedQty(null, null));
    }
}
