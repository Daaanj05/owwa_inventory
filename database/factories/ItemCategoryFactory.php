<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Power Plant Equipment', 'Semi-Expendable', 'Consumables']),
            'description' => fake()->sentence(),
        ];
    }
}
