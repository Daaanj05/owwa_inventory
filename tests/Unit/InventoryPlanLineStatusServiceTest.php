<?php

namespace Tests\Unit;

use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlanLine;
use App\Services\InventoryPlanLineStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPlanLineStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_when_no_session(): void
    {
        $line = PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => now()->addWeek()->toDateString(),
            'physical_count_session_id' => null,
        ]);

        $status = app(InventoryPlanLineStatusService::class)->statusForLine($line);

        $this->assertSame(PhysicalInventoryPlanLine::STATUS_PENDING, $status);
    }

    public function test_in_progress_when_session_not_complete(): void
    {
        $line = PhysicalInventoryPlanLine::factory()->create();

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => now(),
            'status' => PhysicalCountSession::STATUS_IN_PROGRESS,
        ]);

        $line->update(['physical_count_session_id' => $session->id]);

        $status = app(InventoryPlanLineStatusService::class)->statusForLine($line->fresh('physicalCountSession'));

        $this->assertSame(PhysicalInventoryPlanLine::STATUS_IN_PROGRESS, $status);
    }

    public function test_complete_when_session_is_complete(): void
    {
        $line = PhysicalInventoryPlanLine::factory()->create();

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => now(),
            'status' => PhysicalCountSession::STATUS_COMPLETE,
        ]);

        $line->update(['physical_count_session_id' => $session->id]);

        $status = app(InventoryPlanLineStatusService::class)->statusForLine($line->fresh('physicalCountSession'));

        $this->assertSame(PhysicalInventoryPlanLine::STATUS_COMPLETE, $status);
    }

    public function test_overdue_when_date_passed_and_not_complete(): void
    {
        $line = PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => now()->subDays(3)->toDateString(),
            'physical_count_session_id' => null,
        ]);

        $status = app(InventoryPlanLineStatusService::class)->statusForLine($line);

        $this->assertSame(PhysicalInventoryPlanLine::STATUS_OVERDUE, $status);
    }
}
