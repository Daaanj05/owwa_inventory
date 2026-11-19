<?php

namespace Tests\Unit;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Services\InventoryPlanScheduleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPlanScheduleSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expand_slots_creates_one_line_per_active_office(): void
    {
        $officeA = Office::factory()->create(['name' => 'Alpha']);
        $officeB = Office::factory()->create(['name' => 'Beta']);
        Office::factory()->create(['name' => 'Archived', 'archived_at' => now()]);
        $category = ItemCategory::factory()->create();
        $planned = now()->addWeek()->toDateString();

        $lines = app(InventoryPlanScheduleSyncService::class)->expandSlotsToLines([
            [
                'item_category_id' => $category->id,
                'planned_date' => $planned,
            ],
        ]);

        $this->assertCount(2, $lines);
        $this->assertSame(
            [$officeA->id, $officeB->id],
            collect($lines)->pluck('office_id')->sort()->values()->all(),
        );
        $this->assertTrue(collect($lines)->every(
            fn (array $line): bool => (int) $line['item_category_id'] === (int) $category->id
                && $line['planned_date'] === $planned
        ));
    }

    public function test_sync_plan_lines_updates_planned_date_when_no_session(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $plan = PhysicalInventoryPlan::factory()->create(['item_category_id' => $category->id]);
        $planned = now()->addWeek()->toDateString();
        $later = now()->addWeeks(2)->toDateString();

        $line = PhysicalInventoryPlanLine::factory()->create([
            'physical_inventory_plan_id' => $plan->id,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'planned_date' => $planned,
            'physical_count_session_id' => null,
        ]);

        app(InventoryPlanScheduleSyncService::class)->syncPlanLines($plan, [
            [
                'item_category_id' => $category->id,
                'planned_date' => $later,
            ],
        ]);

        $this->assertSame($later, $line->fresh()->planned_date->toDateString());
    }
}
