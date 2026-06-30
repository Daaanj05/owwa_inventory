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
            'name' => 'Category '.fake()->unique()->numerify('########'),
            'description' => fake()->sentence(),
        ];
    }
}
