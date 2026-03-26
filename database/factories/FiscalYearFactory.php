<?php

namespace Database\Factories;

use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 years', 'now');
        $end = (clone $start)->modify('+1 year');

        return [
            'name' => $start->format('Y') . '-' . $end->format('y'),
            'start_date' => $start,
            'end_date' => $end,
            'is_default' => false,
        ];
    }
}
