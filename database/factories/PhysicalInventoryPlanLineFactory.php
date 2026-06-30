<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalInventoryPlanLine>
 */
class PhysicalInventoryPlanLineFactory extends Factory
{
    protected $model = PhysicalInventoryPlanLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory(),
            'office_id' => Office::factory(),
            'item_category_id' => ItemCategory::factory(),
            'planned_date' => now()->addWeek()->toDateString(),
        ];
    }
}
