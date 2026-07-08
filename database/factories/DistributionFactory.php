<?php

namespace Database\Factories;

use App\Models\Distribution;
use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Distribution>
 */
class DistributionFactory extends Factory
{
    protected $model = Distribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'department_id' => null,
            'requisition_id' => null,
            'item_id' => Item::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'distributed_to' => User::factory()->state(['role' => User::ROLE_EMPLOYEE]),
            'distributed_by' => User::factory()->state(['role' => User::ROLE_UNIT_CONSOLIDATOR]),
            'distribution_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
