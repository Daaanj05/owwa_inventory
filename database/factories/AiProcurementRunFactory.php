<?php

namespace Database\Factories;

use App\Models\AiProcurementRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProcurementRun>
 */
class AiProcurementRunFactory extends Factory
{
    protected $model = AiProcurementRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ran_at' => now(),
            'period_from' => now()->subYear()->toDateString(),
            'period_to' => now()->toDateString(),
            'summary' => fake()->sentence(),
            'raw_response' => "[[NEXT ACTION]]\nPrepare one PR.\n\n[[CONSUMABLES]]\nBuy high items.\n\n[[SEMI-EXPENDABLE]]\nNone due.",
            'status' => 'pending',
            'created_by' => User::factory(),
        ];
    }
}
