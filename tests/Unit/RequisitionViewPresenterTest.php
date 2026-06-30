<?php

namespace Tests\Unit;

use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\RequisitionViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_steps_pending_shows_active_review(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create(['office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0001',
            'office_id' => $office->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $steps = RequisitionViewPresenter::workflowSteps($requisition);

        $this->assertSame('active', $steps[1]['state']);
        $this->assertSame('active', $steps[2]['state']);
    }

    public function test_for_record_includes_reference_label(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create(['office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0099',
            'office_id' => $office->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $hero = RequisitionViewPresenter::forRecord($requisition);

        $this->assertSame('2026-01-0099', $hero['reference']);
        $this->assertSame('Reference', $hero['referenceLabel']);
        $this->assertCount(4, $hero['workflowSteps']);
    }
}
