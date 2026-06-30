<?php

namespace Tests\Unit;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Services\InventoryPlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPlanValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_duplicate_office_and_category_on_same_plan(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $cutOff = now()->addMonth()->toDateString();
        $planned = now()->addWeek()->toDateString();

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'FY schedule',
                'cut_off_date' => $cutOff,
            ],
            null,
            [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => $planned,
                ],
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => $planned,
                ],
            ],
        );
    }

    public function test_rejects_planned_date_after_cut_off_date(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $cutOff = now()->addWeek()->toDateString();

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'FY schedule',
                'cut_off_date' => $cutOff,
            ],
            null,
            [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => now()->addMonth()->toDateString(),
                ],
            ],
        );
    }

    public function test_rejects_planned_date_before_today(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $cutOff = now()->addMonth()->toDateString();

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'FY schedule',
                'cut_off_date' => $cutOff,
            ],
            null,
            [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => now()->subDay()->toDateString(),
                ],
            ],
        );
    }

    public function test_rejects_cut_off_date_before_today(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'FY schedule',
                'cut_off_date' => now()->subDay()->toDateString(),
            ],
            null,
            [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => now()->addWeek()->toDateString(),
                ],
            ],
        );
    }

    public function test_rejects_coa_submitted_at_fewer_than_10_days_before_first_planned_date(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $planned = now()->addWeek()->toDateString();
        $cutOff = now()->addMonth()->toDateString();

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'FY schedule',
                'cut_off_date' => $cutOff,
                'coa_submitted_at' => now()->addDay()->toDateString(),
            ],
            null,
            [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $category->id,
                    'planned_date' => $planned,
                ],
            ],
        );
    }

    public function test_blocks_plan_completion_when_a_line_has_no_complete_session(): void
    {
        $plan = PhysicalInventoryPlan::factory()
            ->approved()
            ->create();

        $line = PhysicalInventoryPlanLine::factory()->create([
            'physical_inventory_plan_id' => $plan->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => now(),
            'status' => PhysicalCountSession::STATUS_IN_PROGRESS,
        ]);

        $line->update(['physical_count_session_id' => $session->id]);

        $this->expectException(ValidationException::class);

        app(InventoryPlanValidator::class)->validateCanComplete($plan->fresh(['lines.physicalCountSession']));
    }

    public function test_allows_valid_plan_with_multiple_offices(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $cutOff = now()->addMonth()->toDateString();
        $planned = now()->addWeek()->toDateString();

        app(InventoryPlanValidator::class)->validateForSave(
            [
                'title' => 'Regional schedule',
                'cut_off_date' => $cutOff,
                'coa_submitted_at' => now()->subDays(11)->toDateString(),
            ],
            null,
            [
                [
                    'office_id' => $officeA->id,
                    'item_category_id' => $category->id,
                    'planned_date' => $planned,
                ],
                [
                    'office_id' => $officeB->id,
                    'item_category_id' => $category->id,
                    'planned_date' => $planned,
                ],
            ],
        );

        $this->assertTrue(true);
    }
}
