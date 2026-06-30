<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalInventoryPlan>
 */
class PhysicalInventoryPlanFactory extends Factory
{
    protected $model = PhysicalInventoryPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'period_label' => 'FY '.now()->year,
            'cut_off_date' => now()->addMonth()->endOfMonth()->toDateString(),
            'status' => PhysicalInventoryPlan::STATUS_DRAFT,
            'item_category_id' => ItemCategory::factory(),
            'recorded_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PhysicalInventoryPlan::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{office_id?: int, item_category_id?: int, planned_date?: string}>|null  $lines
     */
    public function withLines(?array $lines = null): static
    {
        return $this->afterCreating(function (PhysicalInventoryPlan $plan) use ($lines): void {
            $office = Office::factory()->create();
            $categoryId = $plan->item_category_id ?? ItemCategory::factory()->create()->id;

            $rows = $lines ?? [
                [
                    'office_id' => $office->id,
                    'item_category_id' => $categoryId,
                    'planned_date' => now()->addWeek()->toDateString(),
                ],
            ];

            foreach ($rows as $row) {
                $plan->lines()->create([
                    'office_id' => $row['office_id'] ?? Office::factory()->create()->id,
                    'item_category_id' => $row['item_category_id'] ?? $categoryId,
                    'planned_date' => $row['planned_date'] ?? now()->addWeek()->toDateString(),
                ]);
            }
        });
    }
}
