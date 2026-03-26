<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfficeFactory extends Factory
{
    protected $model = Office::class;

    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'is_satellite' => fake()->boolean(20),
            'address' => fake()->address(),
        ];
    }
}
