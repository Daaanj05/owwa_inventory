<?php

namespace Tests\Unit;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Services\InventoryPlanStartCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPlanStartCountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_linked_physical_count_session_with_correct_attributes(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $plan = PhysicalInventoryPlan::factory()->approved()->create([
            'item_category_id' => $category->id,
        ]);

        $line = PhysicalInventoryPlanLine::factory()->create([
            'physical_inventory_plan_id' => $plan->id,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'planned_date' => '2026-06-30',
        ]);

        $session = app(InventoryPlanStartCountService::class)->startCount($line, $custodian);

        $this->assertInstanceOf(PhysicalCountSession::class, $session);
        $this->assertSame(PhysicalCountSession::TYPE_RPCPPE, $session->count_type);
        $this->assertSame($office->id, $session->office_id);
        $this->assertSame($category->id, $session->item_category_id);
        $this->assertSame('2026-06-30', $session->count_date->toDateString());
        $this->assertSame($session->id, $line->fresh()->physical_count_session_id);
        $this->assertSame(PhysicalInventoryPlan::STATUS_IN_PROGRESS, $plan->fresh()->status);
    }

    public function test_rejects_second_start_on_same_line(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $line = PhysicalInventoryPlanLine::factory()->create();

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => now(),
        ]);

        $line->update(['physical_count_session_id' => $session->id]);

        $this->expectException(ValidationException::class);

        app(InventoryPlanStartCountService::class)->startCount($line->fresh(), $custodian);
    }
}
